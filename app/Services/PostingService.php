<?php

namespace App\Services;

use App\Contracts\Publisher;
use App\Enums\ConnectionStatus;
use App\Enums\FailureBehavior;
use App\Enums\ItemStatus;
use App\Enums\PostingStatus;
use App\Events\BotConnectionFailed;
use App\Events\DestinationPermissionFailed;
use App\Events\PostDelivered;
use App\Events\PostFailed;
use App\Jobs\RetryTelegramPostJob;
use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\PostingLog;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramErrorType;
use App\Services\Telegram\TelegramService;
use App\Support\Publishing\PublishTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one claimed campaign item and records the outcome.
 */
class PostingService
{
    public function __construct(
        private readonly CampaignService $campaigns,
    ) {}

    /**
     * Deliver the item behind a "processing" posting log.
     *
     * Safe to call more than once for the same log: only the first caller
     * wins the api_called_at flag and talks to Telegram.
     */
    public function deliver(int $postingLogId): string
    {
        $log = PostingLog::query()->find($postingLogId);

        if (! $log || $log->status !== PostingStatus::Processing) {
            return 'stale';
        }

        $item = $log->campaignItem;

        if (! $item || $item->status !== ItemStatus::Processing || (int) $item->current_log_id !== $log->id) {
            return 'stale';
        }

        // Exactly-once guard for the API call.
        $won = PostingLog::query()
            ->whereKey($log->id)
            ->where('status', PostingStatus::Processing)
            ->whereNull('api_called_at')
            ->update(['api_called_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);

        if ($won !== 1) {
            return 'duplicate';
        }

        $log->refresh();

        $campaign = $item->campaign;
        $content = $item->content;
        $bot = $campaign->bot;
        $destination = $log->destination;

        $preflight = match (true) {
            $bot === null || $bot->trashed() => 'The campaign bot has been deleted.',
            ! $bot->isActive() => "Bot \"{$bot->name}\" is disabled.",
            $destination === null || $destination->trashed() => 'The destination has been deleted.',
            ! $destination->isActive() && ! $log->is_test => "Destination \"{$destination->name}\" is inactive.",
            $content === null || $content->trashed() => 'The content has been deleted.',
            default => null,
        };

        if ($preflight !== null) {
            $this->recordFailure($log, $item, new TelegramApiException(TelegramErrorType::BadRequest, $preflight), permanent: true);

            return 'failed';
        }

        if (! $content->isActive()) {
            $this->recordSkipped($log, $item, 'Content is inactive; item skipped.');

            return 'skipped';
        }

        try {
            $result = $this->publisher('telegram')->publish($content, PublishTarget::telegram($bot, $destination->apiChatId()));
        } catch (TelegramApiException $e) {
            $this->recordFailure($log, $item, $e);

            return 'failed';
        } catch (Throwable $e) {
            report($e);
            $message = class_basename($e).': '.TelegramService::redact($e->getMessage());
            $this->recordFailure($log, $item, new TelegramApiException(TelegramErrorType::Unknown, $message));

            return 'failed';
        }

        DB::transaction(function () use ($log, $item, $result, $bot) {
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

            $item->forceFill([
                'status' => ItemStatus::Sent,
                'sent_at' => $now,
                'telegram_message_id' => $result->messageId,
                'next_attempt_at' => null,
                'error_message' => null,
            ])->save();

            TelegramBot::query()->whereKey($bot->id)->update(array_filter([
                'last_activity_at' => $now,
                'connection_status' => $bot->connection_status !== ConnectionStatus::Connected ? ConnectionStatus::Connected : null,
            ]));

            $this->campaigns->afterItemFinished($item->campaign);

            DB::afterCommit(fn () => event(new PostDelivered($log->fresh())));
        });

        Log::channel('campaign')->info('Item delivered', ['log_id' => $log->id, 'item_id' => $item->id, 'message_id' => $result->messageId]);

        return 'sent';
    }

    /**
     * Apply the campaign's failure behaviour to a failed attempt.
     */
    public function recordFailure(PostingLog $log, CampaignItem $item, TelegramApiException $e, bool $permanent = false, bool $allowRetry = true): void
    {
        DB::transaction(function () use ($log, $item, $e, $permanent, $allowRetry) {
            $campaign = Campaign::query()->whereKey($item->campaign_id)->lockForUpdate()->firstOrFail();
            $item = CampaignItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();
            $isFlood = $e->type === TelegramErrorType::FloodControl;

            $log->forceFill([
                'status' => PostingStatus::Failed,
                'error_code' => $e->errorCode,
                'error_message' => mb_substr($e->friendlyMessage(), 0, 1000),
                'response_data' => TelegramService::sanitizeResponse($e->response),
                'duration_ms' => $log->api_called_at ? (int) $log->api_called_at->diffInMilliseconds($now) : null,
            ])->save();

            if ($isFlood) {
                $this->startCooldown($campaign->bot_id, (int) ($e->retryAfter ?? 30));
            } else {
                $item->failed_attempts++;
            }

            $delay = $allowRetry ? $this->retryDelay($campaign, $item, $e, $permanent) : null;
            $item->error_message = mb_substr($e->friendlyMessage(), 0, 1000);

            if ($delay !== null) {
                $item->status = ItemStatus::Pending;
                $item->next_attempt_at = $now->addSeconds($delay);
                $item->save();

                RetryTelegramPostJob::dispatch($campaign->id, $item->id)->delay($item->next_attempt_at)->afterCommit();
            } else {
                $item->status = ItemStatus::Failed;
                $item->next_attempt_at = null;
                $item->save();

                if ($campaign->failure_behavior === FailureBehavior::StopOnFailure) {
                    $this->campaigns->fail($campaign, "Stopped after item #{$item->sort_order} failed: ".$e->friendlyMessage());
                } else {
                    $this->campaigns->afterItemFinished($campaign);
                }
            }

            $this->flagConnectionProblems($campaign, $log, $e);

            $willRetry = $delay !== null;
            DB::afterCommit(fn () => event(new PostFailed($log->fresh(), $willRetry, $e->type)));
        });

        Log::channel('campaign')->warning('Item delivery failed', [
            'log_id' => $log->id,
            'item_id' => $item->id,
            'type' => $e->type->value,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Seconds until the next attempt, or null when the item should be marked failed.
     */
    public function retryDelay(Campaign $campaign, CampaignItem $item, TelegramApiException $e, bool $permanent = false): ?int
    {
        $behavior = $campaign->failure_behavior;

        if ($e->type === TelegramErrorType::FloodControl) {
            $floodWaits = $item->attempts - $item->failed_attempts;

            return $floodWaits <= (int) config('telegram.max_flood_waits') ? max(1, (int) ($e->retryAfter ?? 30)) + 1 : null;
        }

        $canRetry = match ($behavior) {
            FailureBehavior::Continue => false,
            FailureBehavior::RetryUntilSuccess => true,
            FailureBehavior::RetryThenContinue, FailureBehavior::StopOnFailure => ! $permanent && $e->isTemporary()
                && $item->failed_attempts <= (int) $campaign->max_retries,
        };

        if (! $canRetry || ($permanent && $behavior !== FailureBehavior::RetryUntilSuccess)) {
            return null;
        }

        return $this->backoff((int) $campaign->retry_delay_seconds, $item->failed_attempts);
    }

    /**
     * Exponential backoff with a little jitter, capped.
     */
    public function backoff(int $baseSeconds, int $failedAttempts): int
    {
        $base = max(5, $baseSeconds);
        $delay = $base * (2 ** max(0, $failedAttempts - 1));
        $delay = min((int) config('telegram.max_backoff_seconds', 3600), $delay);

        return $delay + random_int(0, (int) min(30, max(1, $delay * 0.1)));
    }

    /**
     * Content was deactivated after being added to the campaign.
     */
    private function recordSkipped(PostingLog $log, CampaignItem $item, string $reason): void
    {
        DB::transaction(function () use ($log, $item, $reason) {
            $log->forceFill(['status' => PostingStatus::Skipped, 'error_message' => $reason])->save();
            $item->forceFill(['status' => ItemStatus::Skipped, 'error_message' => $reason])->save();
            $this->campaigns->afterItemFinished($item->campaign);
        });
    }

    /**
     * Bot-wide cooldown after Telegram flood control; honoured by the job middleware.
     */
    public function startCooldown(int $botId, int $seconds): void
    {
        $until = CarbonImmutable::now()->addSeconds(max(1, $seconds))->getTimestamp();
        $current = (int) Cache::get(self::cooldownKey($botId), 0);

        if ($until > $current) {
            Cache::put(self::cooldownKey($botId), $until, $seconds + 5);
        }
    }

    public static function cooldownKey(int $botId): string
    {
        return "telegram:cooldown:bot:{$botId}";
    }

    private function flagConnectionProblems(Campaign $campaign, PostingLog $log, TelegramApiException $e): void
    {
        if ($e->type->isBotFailure()) {
            $bot = TelegramBot::query()->find($campaign->bot_id);

            if ($bot && $bot->connection_status !== ConnectionStatus::Failed) {
                $bot->forceFill(['connection_status' => ConnectionStatus::Failed, 'last_error' => mb_substr($e->friendlyMessage(), 0, 250)])->save();
                DB::afterCommit(fn () => event(new BotConnectionFailed($bot, $e->friendlyMessage())));
            }
        }

        if ($e->type->isPermissionFailure() && $log->destination_id) {
            $destination = TelegramDestination::query()->find($log->destination_id);

            if ($destination && $destination->connection_status !== ConnectionStatus::Failed) {
                $destination->forceFill(['connection_status' => ConnectionStatus::Failed, 'last_error' => mb_substr($e->friendlyMessage(), 0, 250)])->save();
                DB::afterCommit(fn () => event(new DestinationPermissionFailed($destination, $e->friendlyMessage())));
            }
        }
    }

    private function publisher(string $platform): Publisher
    {
        $class = config("publishing.publishers.{$platform}");

        return app($class);
    }
}
