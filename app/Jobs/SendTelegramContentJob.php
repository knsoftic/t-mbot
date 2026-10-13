<?php

namespace App\Jobs;

use App\Enums\ContentType;
use App\Jobs\Middleware\TelegramRateLimit;
use App\Models\CampaignItem;
use App\Models\PostingLog;
use App\Services\PostingService;
use App\Services\RecoveryService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Base class for the jobs that call the Bot API for one claimed item.
 *
 * One subclass exists per content type so queue monitoring (and the Failed
 * Jobs page) shows what kind of post a job carries, and so heavy media gets
 * a longer timeout than text.
 */
abstract class SendTelegramContentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Releases caused by rate limiting are not failures; retryUntil() bounds them.
     */
    public int $maxExceptions = 2;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct(
        public int $postingLogId,
        public int $botId,
        public string $chatKey,
    ) {
        $this->onQueue(config('telegram.queues.telegram'));
    }

    public static function forItem(CampaignItem $item, PostingLog $log): self
    {
        $type = $item->content?->type ?? ContentType::Text;
        $class = match ($type) {
            ContentType::Text => SendTelegramMessageJob::class,
            ContentType::Image => SendTelegramPhotoJob::class,
            ContentType::Video => SendTelegramVideoJob::class,
            ContentType::Document => SendTelegramDocumentJob::class,
            ContentType::Audio => SendTelegramAudioJob::class,
            ContentType::Animation => SendTelegramAnimationJob::class,
        };

        return new $class($log->id, (int) $log->bot_id, (string) $log->chat_id);
    }

    public function uniqueId(): string
    {
        return (string) $this->postingLogId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new TelegramRateLimit];
    }

    public function handle(PostingService $posting): void
    {
        $posting->deliver($this->postingLogId);
    }

    /**
     * An unexpected exception escaped (database outage, worker timeout, ...).
     */
    public function failed(?Throwable $exception): void
    {
        app(RecoveryService::class)->recoverLog($this->postingLogId, fromFailedJob: true);
    }
}
