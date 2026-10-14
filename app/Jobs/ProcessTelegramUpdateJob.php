<?php

namespace App\Jobs;

use App\Jobs\Middleware\TelegramRateLimit;
use App\Models\TelegramBot;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramErrorType;
use App\Services\WelcomeService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Handles one update Telegram delivered to the webhook (new member, join
 * request, private message). Runs on the Telegram queue: a join-request DM
 * has to go out within 5 minutes.
 */
class ProcessTelegramUpdateJob implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    /** Private chat of the member: per-chat rate limiting. */
    public string $chatKey;

    /**
     * @param  array<string, mixed>  $update
     */
    public function __construct(
        public int $botId,
        public array $update,
    ) {
        $this->onQueue(config('telegram.queues.telegram'));
        $this->chatKey = (string) ($update['chat_join_request']['user_chat_id']
            ?? $update['chat_member']['new_chat_member']['user']['id']
            ?? $update['message']['chat']['id']
            ?? 'update');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new TelegramRateLimit];
    }

    public function handle(WelcomeService $welcome): void
    {
        // Telegram re-delivers an update when the webhook answers slowly: handle it once.
        $key = "telegram:update:{$this->botId}:".($this->update['update_id'] ?? md5(json_encode($this->update)));

        if (! Cache::add($key, true, now()->addDay())) {
            return;
        }

        $bot = TelegramBot::query()->withoutGlobalScopes()->find($this->botId);

        if (! $bot || $bot->trashed() || ! $bot->isActive()) {
            return;
        }

        try {
            $welcome->handle($bot, $this->update);
        } catch (TelegramApiException $e) {
            Cache::forget($key);

            if ($e->type === TelegramErrorType::FloodControl) {
                // Nothing was sent: try again when Telegram allows it.
                $this->release(max(1, (int) ($e->retryAfter ?? 30)) + 1);

                return;
            }

            throw $e;
        } catch (Throwable $e) {
            Cache::forget($key);

            throw $e;
        }
    }
}
