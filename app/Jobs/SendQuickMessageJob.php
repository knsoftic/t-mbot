<?php

namespace App\Jobs;

use App\Enums\PostingStatus;
use App\Jobs\Middleware\TelegramRateLimit;
use App\Models\PostingLog;
use App\Models\Scopes\AccountScope;
use App\Services\QuickSendService;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramErrorType;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one Quick Send message (on the high-priority Telegram queue).
 */
class SendQuickMessageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Waiting for rate limits is not a failure; retryUntil() bounds it. */
    public int $maxExceptions = 1;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public function __construct(
        public int $postingLogId,
        public int $botId,
        public string $chatKey,
    ) {
        $this->onQueue(config('telegram.queues.telegram'));
    }

    public function uniqueId(): string
    {
        return 'quick-'.$this->postingLogId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new TelegramRateLimit];
    }

    public function handle(QuickSendService $quickSend): void
    {
        $outcome = $quickSend->deliver($this->postingLogId);

        if (is_int($outcome)) {
            $this->release($outcome);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $log = PostingLog::query()->withoutGlobalScope(AccountScope::class)->find($this->postingLogId);

        if ($log && $log->status === PostingStatus::Processing) {
            app(QuickSendService::class)->fail($log, new TelegramApiException(
                TelegramErrorType::Unknown,
                $log->api_called_at
                    ? 'The worker stopped while sending; the message may or may not have arrived. Check the chat before sending again.'
                    : 'The message could not be sent in time. Try again.',
            ));
        }
    }
}
