<?php

namespace App\Jobs;

use App\Enums\PostingStatus;
use App\Jobs\Middleware\TelegramRateLimit;
use App\Models\PostingLog;
use App\Models\Scopes\AccountScope;
use App\Services\AutoQueueService;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramErrorType;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Posts one Auto Queue item (Telegram queue, same rate limits as campaigns).
 */
class SendAutoQueuePostJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 1;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $postingLogId,
        public int $botId,
        public string $chatKey,
    ) {
        $this->onQueue(config('telegram.queues.telegram'));
    }

    public function uniqueId(): string
    {
        return 'auto-'.$this->postingLogId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new TelegramRateLimit];
    }

    public function handle(AutoQueueService $queues): void
    {
        $outcome = $queues->deliver($this->postingLogId);

        if (is_int($outcome)) {
            $this->release($outcome);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $log = PostingLog::query()->withoutGlobalScope(AccountScope::class)->find($this->postingLogId);

        if ($log && $log->status === PostingStatus::Processing) {
            app(AutoQueueService::class)->fail($log, new TelegramApiException(TelegramErrorType::Unknown, 'Posting stopped unexpectedly.'),
                $log->api_called_at ? 'The worker stopped while posting; the post may or may not have arrived. Check the channel.' : 'The post could not be sent in time.');
        }
    }
}
