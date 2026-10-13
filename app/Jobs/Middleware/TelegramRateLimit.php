<?php

namespace App\Jobs\Middleware;

use App\Jobs\SendTelegramContentJob;
use App\Services\PostingService;
use App\Services\SettingsService;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Keeps send jobs inside Telegram's limits.
 *
 *  - While a bot is in a flood-control cooldown (retry_after), its jobs are
 *    released until the cooldown ends: no request is made during it.
 *  - Per bot: at most N requests per second (Telegram allows ~30/s).
 *  - Per chat: at most N posts per minute (Telegram allows ~20/min in groups).
 */
class TelegramRateLimit
{
    public function handle(SendTelegramContentJob $job, Closure $next): void
    {
        $now = now()->getTimestamp();
        $cooldownUntil = (int) Cache::get(PostingService::cooldownKey($job->botId), 0);

        if ($cooldownUntil > $now) {
            $job->release($cooldownUntil - $now + 1);

            return;
        }

        $settings = app(SettingsService::class);
        $botKey = 'telegram:rate:bot:'.$job->botId;
        $chatKey = 'telegram:rate:chat:'.$job->botId.':'.$job->chatKey;
        $perSecond = max(1, (int) $settings->get('telegram.per_bot_per_second'));
        $perMinute = max(1, (int) $settings->get('telegram.per_chat_per_minute'));

        if (RateLimiter::tooManyAttempts($botKey, $perSecond)) {
            $job->release(max(1, RateLimiter::availableIn($botKey)));

            return;
        }

        if (RateLimiter::tooManyAttempts($chatKey, $perMinute)) {
            $job->release(max(1, RateLimiter::availableIn($chatKey)));

            return;
        }

        RateLimiter::hit($botKey, 1);
        RateLimiter::hit($chatKey, 60);

        $next($job);
    }
}
