<?php

namespace App\Services;

use App\Enums\PostingStatus;
use App\Enums\PostTrigger;
use App\Jobs\SendAutoQueuePostJob;
use App\Models\AutoQueue;
use App\Models\AutoQueuePost;
use App\Models\Content;
use App\Models\PostingLog;
use App\Models\Scopes\AccountScope;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Models\User;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramErrorType;
use App\Services\Telegram\TelegramService;
use App\Support\Accounts;
use App\Support\Publishing\PublishTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Auto Queue: at each posting time the next Content Library item goes to the
 * queue's channel. New content joins the line by itself; when every item has
 * been posted, a new round starts from the beginning.
 */
class AutoQueueService
{
    public const MAX_TIMES = 24;

    /** A slot missed by more than this (server was down) is skipped, not posted late. */
    public const LATE_LIMIT_MINUTES = 15;

    /** Network errors (request never reached Telegram) are retried this many times. */
    private const NETWORK_ATTEMPTS = 3;

    public function __construct(
        private readonly PlanLimiter $limiter,
        private readonly PostingService $posting,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------
    // Setup
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'bot_id' => ['required', 'integer', Accounts::exists('telegram_bots')],
            'destination_id' => ['required', 'integer', Accounts::exists('telegram_destinations')],
            'timezone' => ['required', 'timezone:all'],
            'mode' => ['nullable', Rule::in([AutoQueue::TIMES, AutoQueue::INTERVAL])],
            // Checked against the mode (and the plan) in save().
            'interval_seconds' => ['nullable', 'integer', 'min:1', 'max:604800'],
            'times' => ['array', 'max:'.self::MAX_TIMES],
            'times.*' => ['required', 'date_format:H:i', 'distinct'],
            'days' => ['array'],
            'days.*' => ['integer', 'between:1,7'],
            'active_from' => ['nullable', 'date_format:H:i'],
            'active_until' => ['nullable', 'date_format:H:i'],
            'tag_id' => ['nullable', 'integer', Accounts::exists('tags', softDeletes: false)],
            'order' => ['required', Rule::in(array_keys(AutoQueue::ORDERS))],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  Validated with rules().
     */
    public function save(array $data, ?AutoQueue $queue = null, ?User $user = null): AutoQueue
    {
        $destination = TelegramDestination::query()->withoutGlobalScope(AccountScope::class)->findOrFail($data['destination_id']);

        if ((int) $destination->bot_id !== (int) $data['bot_id']) {
            throw ValidationException::withMessages(['destination_id' => 'This channel / group is connected to a different bot.']);
        }

        $ownerId = TelegramBot::withTrashed()->withoutGlobalScope(AccountScope::class)->whereKey($data['bot_id'])->value('user_id');
        $mode = ($data['mode'] ?? null) ?: AutoQueue::TIMES;
        $interval = $mode === AutoQueue::INTERVAL;
        $times = $interval ? [] : collect($data['times'] ?? [])->map(fn ($t) => substr((string) $t, 0, 5))->unique()->sort()->values()->all();
        $days = collect($data['days'] ?? [])->map(fn ($d) => (int) $d)->unique()->sort()->values()->all();
        $from = $interval ? (($data['active_from'] ?? null) ?: null) : null;
        $until = $interval ? (($data['active_until'] ?? null) ?: null) : null;

        if (! $interval && $times === []) {
            throw ValidationException::withMessages(['times' => 'Add at least one posting time.']);
        }

        if ($interval) {
            $seconds = (int) ($data['interval_seconds'] ?? 0);
            $planMinimum = $this->limiter->minIntervalSeconds($ownerId === null ? null : (int) $ownerId);

            if ($seconds < AutoQueue::MIN_INTERVAL_SECONDS) {
                throw ValidationException::withMessages(['interval_seconds' => 'The fastest is every '.AutoQueue::MIN_INTERVAL_SECONDS.' seconds: Telegram allows only about 20 posts per minute in one chat.']);
            }

            if (Accounts::isCustomer($ownerId === null ? null : (int) $ownerId) && $seconds < $planMinimum) {
                throw ValidationException::withMessages(['interval_seconds' => 'Your plan allows posting at most every '.intdiv($planMinimum, 60).' minute(s). Choose a longer interval or upgrade your plan.']);
            }
        }

        if (($from === null) !== ($until === null) || ($from !== null && $from === $until)) {
            throw ValidationException::withMessages(['active_from' => 'Set both the start and the end of the posting hours (they must differ), or leave both empty.']);
        }

        if ($days === []) {
            throw ValidationException::withMessages(['days' => 'Choose at least one day.']);
        }

        $isNew = $queue === null;
        $queue ??= new AutoQueue(['status' => AutoQueue::ACTIVE, 'created_by' => $user?->id]);
        $queue->fill([
            // Same account as the bot.
            'user_id' => $ownerId,
            'name' => trim($data['name']),
            'bot_id' => (int) $data['bot_id'],
            'destination_id' => $destination->id,
            'timezone' => $data['timezone'],
            'mode' => $mode,
            'interval_seconds' => $interval ? (int) $data['interval_seconds'] : null,
            'times' => $times,
            'days' => count($days) === 7 ? null : $days,
            'active_from' => $from,
            'active_until' => $until,
            'tag_id' => ($data['tag_id'] ?? null) ?: null,
            'order' => $data['order'],
        ]);

        $queue->next_run_at = $queue->isActive() ? $this->nextSlot($queue, CarbonImmutable::now()) : null;
        $queue->last_error = null;
        $queue->save();

        $this->audit->log($isNew ? 'auto_queue.created' : 'auto_queue.updated', $queue, "Auto Queue “{$queue->name}” saved", ['times' => $times], $user);

        return $queue;
    }

    public function pause(AutoQueue $queue, ?User $user = null): void
    {
        $queue->forceFill(['status' => AutoQueue::PAUSED, 'next_run_at' => null])->save();
        $this->audit->log('auto_queue.paused', $queue, "Auto Queue “{$queue->name}” paused", [], $user);
    }

    public function resume(AutoQueue $queue, ?User $user = null): void
    {
        $queue->status = AutoQueue::ACTIVE;
        $queue->forceFill(['next_run_at' => $this->nextSlot($queue, CarbonImmutable::now()), 'last_error' => null])->save();
        $this->audit->log('auto_queue.resumed', $queue, "Auto Queue “{$queue->name}” resumed", [], $user);
    }

    /**
     * The first posting time after $after (UTC), or null when no day is selected.
     * $previous is the time just used: intervals continue from it, so they never drift.
     */
    public function nextSlot(AutoQueue $queue, CarbonImmutable $after, ?CarbonImmutable $previous = null): ?CarbonImmutable
    {
        if ($queue->isInterval()) {
            return $this->nextIntervalSlot($queue, $after, $previous);
        }

        $local = $after->setTimezone($queue->timezone);
        $times = collect($queue->times ?? [])->sort()->values();

        for ($day = 0; $day <= 7; $day++) {
            $date = $local->startOfDay()->addDays($day);

            if ($queue->days && ! in_array($date->dayOfWeekIso, $queue->days, true)) {
                continue;
            }

            foreach ($times as $time) {
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $slot = $date->setTime($hour, $minute);

                if ($slot->greaterThan($local)) {
                    return $slot->utc();
                }
            }
        }

        return null;
    }

    /**
     * The next $count posting times.
     *
     * @return list<CarbonImmutable>
     */
    public function upcomingSlots(AutoQueue $queue, int $count): array
    {
        $slot = $queue->next_run_at ?? $this->nextSlot($queue, CarbonImmutable::now());
        $slots = [];

        while ($slot && count($slots) < $count) {
            $slots[] = $slot;
            $slot = $this->nextSlot($queue, $slot, $slot);
        }

        return $slots;
    }

    /**
     * Every N seconds, inside the chosen days and daily hours. Missed ticks are
     * not caught up: the next one after $after is used.
     */
    private function nextIntervalSlot(AutoQueue $queue, CarbonImmutable $after, ?CarbonImmutable $previous): ?CarbonImmutable
    {
        $step = max(AutoQueue::MIN_INTERVAL_SECONDS, (int) $queue->interval_seconds);
        $candidate = ($previous ?? $after)->addSeconds($step);

        if ($candidate->lessThanOrEqualTo($after)) {
            $missed = intdiv((int) $candidate->diffInSeconds($after, true), $step) + 1;
            $candidate = $candidate->addSeconds($missed * $step);
        }

        return $this->insidePostingHours($queue, $candidate);
    }

    /**
     * $time when it is inside an allowed day and the daily posting hours,
     * otherwise the next moment posting hours open (UTC).
     */
    private function insidePostingHours(AutoQueue $queue, CarbonImmutable $time): ?CarbonImmutable
    {
        $local = $time->setTimezone($queue->timezone);
        $from = $queue->active_from ?? '00:00';

        // Yesterday's window can run past midnight (e.g. 22:00–02:00).
        for ($day = -1; $day <= 8; $day++) {
            $date = $local->startOfDay()->addDays($day);

            if ($queue->days && ! in_array($date->dayOfWeekIso, $queue->days, true)) {
                continue;
            }

            $start = $date->setTimeFromTimeString($from);
            $end = $queue->active_until ? $date->setTimeFromTimeString($queue->active_until) : $date->addDay();

            if ($end->lessThanOrEqualTo($start)) {
                $end = $end->addDay();
            }

            if ($local->greaterThanOrEqualTo($start) && $local->lessThan($end)) {
                return $time;
            }

            if ($start->greaterThan($local)) {
                return $start->utc();
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Content order
    // ------------------------------------------------------------------

    /**
     * Content the queue may post: active items of the same account (and tag).
     *
     * @return Builder<Content>
     */
    public function eligible(AutoQueue $queue): Builder
    {
        $query = Content::query()->withoutGlobalScope(AccountScope::class)
            ->ownedBy($queue->user_id)
            ->active()
            ->when($queue->tag_id, fn (Builder $q) => $q->whereHas('tags', fn (Builder $t) => $t->withoutGlobalScope(AccountScope::class)->where('tags.id', $queue->tag_id)));

        return match ($queue->order) {
            'newest' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'random' => $query->inRandomOrder(),
            default => $query->orderBy('created_at')->orderBy('id'),
        };
    }

    /**
     * Items not posted yet in the current round, in posting order.
     *
     * @return Builder<Content>
     */
    public function remaining(AutoQueue $queue): Builder
    {
        $posted = AutoQueuePost::query()->where('auto_queue_id', $queue->id)->where('round', $queue->round)->whereNotNull('content_id')->select('content_id');

        return $this->eligible($queue)->whereNotIn('contents.id', $posted);
    }

    /**
     * What the next posts will be (wrapping into the next round), for previews.
     *
     * @return Collection<int, Content>
     */
    public function upcomingContents(AutoQueue $queue, int $count): Collection
    {
        if ($queue->order === 'random') {
            return collect();
        }

        $list = $this->remaining($queue)->limit($count)->get(['contents.id', 'contents.title', 'contents.type']);

        if ($list->count() < $count) {
            $all = $this->eligible($queue)->limit($count)->get(['contents.id', 'contents.title', 'contents.type']);

            while ($all->isNotEmpty() && $list->count() < $count) {
                $list = $list->concat($all->take($count - $list->count()));
            }
        }

        return $list->values();
    }

    // ------------------------------------------------------------------
    // Posting
    // ------------------------------------------------------------------

    /**
     * Queue the post of every queue whose time has come (called every 10 seconds).
     */
    public function dispatchDue(): int
    {
        return AutoQueue::query()->withoutGlobalScope(AccountScope::class)
            ->where('status', AutoQueue::ACTIVE)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->limit(500)
            ->pluck('id')
            ->filter(fn (int $id) => $this->runSlot($id) !== null)
            ->count();
    }

    /**
     * Claim the due posting time of a queue (or post now when $manual) and queue the post.
     *
     * @throws ValidationException when a manual post is not possible
     */
    public function runSlot(int $queueId, bool $manual = false): ?PostingLog
    {
        return DB::transaction(function () use ($queueId, $manual) {
            $queue = AutoQueue::query()->withoutGlobalScope(AccountScope::class)->whereKey($queueId)->lockForUpdate()->first();
            $now = CarbonImmutable::now();

            if (! $queue) {
                return null;
            }

            $slot = $now;

            if (! $manual) {
                if (! $queue->isActive() || ! $queue->next_run_at || $queue->next_run_at->greaterThan($now)) {
                    return null;
                }

                // Whatever happens below, the queue moves on to its next time.
                $slot = $queue->next_run_at;
                $queue->next_run_at = $this->nextSlot($queue, $now, $slot);

                if ($slot->lessThan($now->subMinutes(self::LATE_LIMIT_MINUTES))) {
                    return $this->skip($queue, 'The '.$this->local($queue, $slot).' post was skipped: the server was not running at that time.');
                }
            }

            $problem = $this->problem($queue);

            if ($problem === null && $queue->user_id) {
                $owner = User::query()->whereKey($queue->user_id)->lockForUpdate()->first();

                if ($owner?->isCustomer() && ($block = $this->limiter->sendingBlock($owner, $now))) {
                    $problem = "Your plan's posting limit for this {$block['period']} is used up ({$block['used']}/{$block['limit']}).";
                }
            }

            $content = $problem === null ? $this->remaining($queue)->first() : null;

            // Everything in this round was posted: start again from the beginning.
            if ($problem === null && ! $content && $this->eligible($queue)->exists()) {
                $queue->round++;
                $content = $this->remaining($queue)->first();
            }

            if ($problem === null && ! $content) {
                $problem = 'There is no active content'.($queue->tag_id ? ' with the chosen tag' : '').' in the Content Library.';
            }

            if ($problem !== null) {
                if ($manual) {
                    throw ValidationException::withMessages(['queue' => $problem]);
                }

                return $this->skip($queue, 'The '.$this->local($queue, $slot).' post was skipped: '.$problem);
            }

            $destination = $queue->destination;
            $log = PostingLog::query()->create([
                'user_id' => $queue->user_id,
                'bot_id' => $queue->bot_id,
                'destination_id' => $destination->id,
                'content_id' => $content->id,
                'status' => PostingStatus::Processing,
                'trigger' => PostTrigger::AutoQueue,
                'chat_id' => $destination->apiChatId(),
                'cycle' => $queue->round,
                'attempts' => 1,
                'scheduled_at' => $slot,
            ]);

            AutoQueuePost::query()->create([
                'auto_queue_id' => $queue->id,
                'content_id' => $content->id,
                'round' => $queue->round,
                'posting_log_id' => $log->id,
                'status' => AutoQueuePost::SENDING,
            ]);

            $queue->last_run_at = $now;
            $queue->last_error = null;
            $queue->save();

            dispatch(new SendAutoQueuePostJob($log->id, (int) $queue->bot_id, (string) $log->chat_id))->afterCommit();

            return $log;
        });
    }

    /**
     * Send a claimed post.
     *
     * @return string|int "sent", "failed", "stale" / "duplicate", or seconds to wait before trying again.
     */
    public function deliver(int $postingLogId): string|int
    {
        $log = PostingLog::query()->withoutGlobalScope(AccountScope::class)->find($postingLogId);

        if (! $log || $log->status !== PostingStatus::Processing || $log->trigger !== PostTrigger::AutoQueue) {
            return 'stale';
        }

        // Exactly-once guard: only the first worker talks to Telegram.
        $won = PostingLog::query()->withoutGlobalScope(AccountScope::class)
            ->whereKey($log->id)->where('status', PostingStatus::Processing)->whereNull('api_called_at')
            ->update(['api_called_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);

        if ($won !== 1) {
            return 'duplicate';
        }

        $log->refresh();
        $bot = TelegramBot::withTrashed()->withoutGlobalScope(AccountScope::class)->find($log->bot_id);
        $content = Content::withTrashed()->withoutGlobalScope(AccountScope::class)->find($log->content_id);

        $problem = match (true) {
            ! $bot || $bot->trashed() || ! $bot->isActive() => 'The bot was deleted or disabled before posting.',
            ! $content || $content->trashed() || ! $content->isActive() => 'The content was deleted or deactivated before posting.',
            default => null,
        };

        if ($problem !== null) {
            return $this->fail($log, new TelegramApiException(TelegramErrorType::BadRequest, $problem), $problem);
        }

        try {
            $result = app(config('publishing.publishers.telegram'))->publish($content, PublishTarget::telegram($bot, (string) $log->chat_id));
        } catch (TelegramApiException $e) {
            $wait = match (true) {
                $e->type === TelegramErrorType::FloodControl => max(1, (int) ($e->retryAfter ?? 30)) + 1,
                $e->type === TelegramErrorType::Network && $log->attempts < self::NETWORK_ATTEMPTS => 30 * $log->attempts,
                default => null,
            };

            if ($wait !== null) {
                // Not delivered: try again later.
                if ($e->type === TelegramErrorType::FloodControl) {
                    $this->posting->startCooldown($bot->id, $wait);
                } else {
                    $log->attempts++;
                }

                $log->forceFill(['api_called_at' => null, 'error_message' => $e->friendlyMessage()])->save();

                return $wait;
            }

            return $this->fail($log, $e);
        } catch (Throwable $e) {
            report($e);

            return $this->fail($log, new TelegramApiException(TelegramErrorType::Unknown, class_basename($e).': '.TelegramService::redact($e->getMessage())));
        }

        $now = CarbonImmutable::now();
        $log->forceFill([
            'status' => PostingStatus::Sent,
            'telegram_message_id' => $result->messageId,
            'sent_at' => $now,
            'duration_ms' => $result->durationMs,
            'response_data' => $result->response + ['_media_source' => $result->mediaSource],
            'error_message' => null,
            'error_code' => null,
        ])->save();

        AutoQueuePost::query()->where('posting_log_id', $log->id)->update(['status' => AutoQueuePost::SENT, 'updated_at' => $now]);
        TelegramBot::query()->withoutGlobalScope(AccountScope::class)->whereKey($bot->id)->update(['last_activity_at' => $now]);

        return 'sent';
    }

    public function fail(PostingLog $log, TelegramApiException $e, ?string $message = null): string
    {
        $message ??= $e->type === TelegramErrorType::Timeout
            ? 'Telegram did not answer in time; the post may still have arrived. Check the channel.'
            : $e->friendlyMessage();

        $log->forceFill([
            'status' => PostingStatus::Failed,
            'error_code' => $e->errorCode,
            'error_message' => mb_substr($message, 0, 1000),
            'response_data' => TelegramService::sanitizeResponse($e->response),
        ])->save();

        AutoQueuePost::query()->where('posting_log_id', $log->id)->update(['status' => AutoQueuePost::FAILED, 'updated_at' => now()]);
        AutoQueue::query()->withoutGlobalScope(AccountScope::class)
            ->whereKey(AutoQueuePost::query()->where('posting_log_id', $log->id)->value('auto_queue_id'))
            ->update(['last_error' => mb_substr('Last post failed: '.$message, 0, 250)]);

        Log::channel('campaign')->warning('Auto Queue post failed', ['log_id' => $log->id, 'type' => $e->type->value, 'error' => $e->getMessage()]);

        return 'failed';
    }

    private function problem(AutoQueue $queue): ?string
    {
        $bot = $queue->bot;
        $destination = $queue->destination;

        return match (true) {
            ! $bot || $bot->trashed() => 'its bot was deleted.',
            ! $bot->isActive() => "bot “{$bot->name}” is disabled.",
            ! $destination || $destination->trashed() => 'its channel was deleted.',
            ! $destination->isActive() => "“{$destination->name}” is inactive.",
            default => null,
        };
    }

    private function skip(AutoQueue $queue, string $reason): null
    {
        $queue->last_error = mb_substr($reason, 0, 250);
        $queue->save();
        Log::channel('campaign')->info('Auto Queue slot skipped', ['auto_queue_id' => $queue->id, 'reason' => $reason]);

        return null;
    }

    private function local(AutoQueue $queue, CarbonImmutable $time): string
    {
        return $time->setTimezone($queue->timezone)->format('H:i');
    }
}
