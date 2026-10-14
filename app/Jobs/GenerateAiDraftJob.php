<?php

namespace App\Jobs;

use App\Services\Ai\AiException;
use App\Services\Ai\PostWriter;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Writes an AI draft in the background (small CPU models need 10–60 s) and
 * leaves the result in the cache for the content form to pick up.
 */
class GenerateAiDraftJob implements ShouldQueue
{
    use Queueable;

    /** A crash fails the job straight away; waiting for the lock does not count. */
    public int $maxExceptions = 1;

    public int $timeout = 210;

    /**
     * @param  array<string, mixed>  $brief
     */
    public function __construct(
        public string $cacheKey,
        public array $brief,
    ) {
        $this->onQueue('default');
        $this->timeout = (int) config('ai.timeout') + 30;
    }

    /**
     * Drafts waiting behind another draft are given up after this.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function handle(PostWriter $writer): void
    {
        // The model answers one request at a time on a small server. Only one
        // worker may wait on it, so the other workers keep posting to Telegram.
        $lock = Cache::lock('ai:generation', $this->timeout);

        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {
            $text = $writer->draft($this->brief);
            Cache::put($this->cacheKey, ['status' => 'done', 'text' => $text], now()->addMinutes(30));
        } catch (AiException $e) {
            Cache::put($this->cacheKey, ['status' => 'failed', 'error' => $e->getMessage()], now()->addMinutes(30));
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put($this->cacheKey, ['status' => 'failed', 'error' => 'The AI job stopped unexpectedly (timeout, server error or too long a wait). Try again.'], now()->addMinutes(30));
    }

    public static function cacheKey(int $userId, string $requestId): string
    {
        return "ai:draft:{$userId}:{$requestId}";
    }
}
