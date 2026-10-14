<?php

namespace App\Services;

use App\Enums\ConnectionStatus;
use App\Enums\ParseMode;
use App\Enums\PostingStatus;
use App\Enums\PostTrigger;
use App\Jobs\SendQuickMessageJob;
use App\Models\PostingLog;
use App\Models\QuickSendTarget;
use App\Models\Scopes\AccountScope;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Models\User;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramErrorType;
use App\Services\Telegram\TelegramService;
use App\Support\Accounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Quick Send: a typed message goes straight to a saved bot → chat pair.
 *
 * Sending still happens on the queue (never inside the web request), with the
 * same flood control, logging and plan limits as campaign posts.
 */
class QuickSendService
{
    public const MAX_LENGTH = 4096;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly PlanLimiter $limiter,
        private readonly PostingService $posting,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function targetRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'bot_id' => ['required', 'integer', Accounts::exists('telegram_bots')],
            'destination_id' => ['required', 'integer', Accounts::exists('telegram_destinations')],
            'parse_mode' => ['nullable', Rule::enum(ParseMode::class)],
            'disable_notification' => ['boolean'],
            'disable_web_page_preview' => ['boolean'],
            'protect_content' => ['boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  Validated with targetRules().
     */
    public function saveTarget(array $data, ?QuickSendTarget $target, ?User $user): QuickSendTarget
    {
        $destination = TelegramDestination::query()->withoutGlobalScope(AccountScope::class)->findOrFail($data['destination_id']);

        if ((int) $destination->bot_id !== (int) $data['bot_id']) {
            throw ValidationException::withMessages(['destination_id' => 'This channel / group is connected to a different bot.']);
        }

        $target ??= new QuickSendTarget;
        $target->fill([
            // Same account as the bot, like campaigns.
            'user_id' => TelegramBot::withTrashed()->withoutGlobalScope(AccountScope::class)->whereKey($data['bot_id'])->value('user_id'),
            'name' => trim($data['name']),
            'bot_id' => (int) $data['bot_id'],
            'destination_id' => (int) $data['destination_id'],
            'parse_mode' => ($data['parse_mode'] ?? null) ?: null,
            'disable_notification' => (bool) ($data['disable_notification'] ?? false),
            'disable_web_page_preview' => (bool) ($data['disable_web_page_preview'] ?? false),
            'protect_content' => (bool) ($data['protect_content'] ?? false),
        ]);

        $isNew = ! $target->exists;
        $target->save();
        $this->audit->log($isNew ? 'quick_send.target_created' : 'quick_send.target_updated', $target, "Quick Send target “{$target->name}” saved", [], $user);

        return $target;
    }

    /**
     * Queue one message for immediate delivery and return its posting log.
     *
     * @throws ValidationException
     */
    public function send(QuickSendTarget $target, string $text, ?User $user = null): PostingLog
    {
        $text = trim(str_replace("\r\n", "\n", $text));

        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['message' => 'Write a message of 1 to '.number_format(self::MAX_LENGTH).' characters.']);
        }

        $bot = $target->bot;
        $destination = $target->destination;

        $problem = match (true) {
            $bot === null || $bot->trashed() => 'The bot of this target has been deleted.',
            ! $bot->isActive() => "Bot “{$bot->name}” is disabled. Enable it on the Bots page.",
            $destination === null || $destination->trashed() => 'The channel / group of this target has been deleted.',
            ! $destination->isActive() => "“{$destination->name}” is inactive. Activate it first.",
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['message' => $problem]);
        }

        $log = DB::transaction(function () use ($target, $destination, $text) {
            // Quick Send messages count towards the account's daily / weekly / monthly limit.
            if ($target->user_id !== null) {
                $owner = User::query()->whereKey($target->user_id)->lockForUpdate()->first();

                if ($owner && $owner->isCustomer() && ($block = $this->limiter->sendingBlock($owner))) {
                    $resumes = $block['resets_at']->setTimezone($owner->displayTimezone())->format('d M, H:i');

                    throw ValidationException::withMessages([
                        'message' => "Your plan's posting limit for this {$block['period']} is used up ({$block['used']}/{$block['limit']}). It resets on {$resumes} — or upgrade your plan.",
                    ]);
                }
            }

            $now = CarbonImmutable::now();
            $target->forceFill(['last_used_at' => $now])->save();

            return PostingLog::query()->create([
                'user_id' => $target->user_id,
                'quick_send_target_id' => $target->id,
                'bot_id' => $target->bot_id,
                'destination_id' => $destination->id,
                'message' => $text,
                'status' => PostingStatus::Processing,
                'trigger' => PostTrigger::QuickSend,
                'is_test' => false,
                'chat_id' => $destination->apiChatId(),
                'cycle' => 1,
                'attempts' => 1,
                'scheduled_at' => $now,
            ]);
        });

        dispatch(new SendQuickMessageJob($log->id, (int) $target->bot_id, (string) $log->chat_id));

        $this->audit->log('quick_send.sent', $target, "Quick Send to “{$destination->name}”", ['log_id' => $log->id, 'length' => mb_strlen($text)], $user);

        return $log;
    }

    /**
     * Call the Bot API for a queued Quick Send message.
     *
     * @return string|int "sent", "failed", "stale" / "duplicate", or seconds to wait (flood control).
     */
    public function deliver(int $postingLogId): string|int
    {
        $log = PostingLog::query()->withoutGlobalScope(AccountScope::class)->find($postingLogId);

        if (! $log || $log->status !== PostingStatus::Processing || $log->trigger !== PostTrigger::QuickSend) {
            return 'stale';
        }

        // Exactly-once guard: only the first worker talks to Telegram.
        $won = PostingLog::query()->withoutGlobalScope(AccountScope::class)
            ->whereKey($log->id)
            ->where('status', PostingStatus::Processing)
            ->whereNull('api_called_at')
            ->update(['api_called_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);

        if ($won !== 1) {
            return 'duplicate';
        }

        $log->refresh();
        $bot = TelegramBot::withTrashed()->withoutGlobalScope(AccountScope::class)->find($log->bot_id);
        $target = QuickSendTarget::query()->withoutGlobalScope(AccountScope::class)->find($log->quick_send_target_id);

        if (! $bot || $bot->trashed() || ! $bot->isActive()) {
            return $this->fail($log, new TelegramApiException(TelegramErrorType::BadRequest, 'The bot was deleted or disabled before the message could be sent.'));
        }

        try {
            $started = microtime(true);
            $result = $this->telegram->forBot($bot)->sendMessage($log->chat_id, (string) $log->message, $target?->sendOptions() ?? []);
        } catch (TelegramApiException $e) {
            if ($e->type === TelegramErrorType::FloodControl) {
                // Not delivered: wait out Telegram's retry_after and try again.
                $wait = max(1, (int) ($e->retryAfter ?? 30)) + 1;
                $this->posting->startCooldown($bot->id, $wait);
                $log->forceFill(['api_called_at' => null, 'error_message' => "Telegram asked to slow down; retrying in {$wait}s."])->save();

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
            'telegram_message_id' => isset($result['message_id']) ? (string) $result['message_id'] : null,
            'sent_at' => $now,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'response_data' => TelegramService::sanitizeResponse($result),
            'error_message' => null,
            'error_code' => null,
        ])->save();

        TelegramBot::query()->withoutGlobalScope(AccountScope::class)->whereKey($bot->id)->update(['last_activity_at' => $now]);
        Log::channel('campaign')->info('Quick Send delivered', ['log_id' => $log->id, 'message_id' => $log->telegram_message_id]);

        return 'sent';
    }

    public function fail(PostingLog $log, TelegramApiException $e): string
    {
        // Quick Send never re-sends on its own (a timeout may still have been delivered).
        $message = match ($e->type) {
            TelegramErrorType::Network => 'Could not reach the Telegram API (network error). The message was not sent — press Send again.',
            TelegramErrorType::Timeout => 'Telegram did not answer in time. Check the chat before sending again: the message may have arrived.',
            TelegramErrorType::ServerError => 'Telegram had a temporary server error. Check the chat, then send again if needed.',
            default => $e->friendlyMessage(),
        };

        $log->forceFill([
            'status' => PostingStatus::Failed,
            'error_code' => $e->errorCode,
            'error_message' => mb_substr($message, 0, 1000),
            'response_data' => TelegramService::sanitizeResponse($e->response),
        ])->save();

        if ($e->type->isPermissionFailure() && $log->destination_id) {
            TelegramDestination::query()->withoutGlobalScope(AccountScope::class)->whereKey($log->destination_id)
                ->update(['connection_status' => ConnectionStatus::Failed, 'last_error' => mb_substr($e->friendlyMessage(), 0, 250)]);
        }

        Log::channel('campaign')->warning('Quick Send failed', ['log_id' => $log->id, 'type' => $e->type->value, 'error' => $e->getMessage()]);

        return 'failed';
    }
}
