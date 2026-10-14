<?php

namespace App\Jobs;

use App\Jobs\Middleware\TelegramRateLimit;
use App\Models\TelegramBot;
use App\Services\MemberAllowlist;
use App\Services\PostingService;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramErrorType;
use App\Services\Telegram\TelegramService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Approves a join request (after the welcome DM went out).
 */
class ApproveJoinRequestJob implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 2;

    public string $chatKey;

    public function __construct(
        public int $botId,
        public string $chatId,
        public int $telegramUserId,
        public ?int $destinationId = null,
        public ?string $username = null,
    ) {
        $this->onQueue(config('telegram.queues.telegram'));
        $this->chatKey = $chatId;
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

    public function handle(TelegramService $telegram, PostingService $posting, MemberAllowlist $allowlist): void
    {
        $bot = TelegramBot::query()->withoutGlobalScopes()->find($this->botId);

        if (! $bot || $bot->trashed() || ! $bot->isActive()) {
            return;
        }

        try {
            $telegram->forBot($bot)->approveChatJoinRequest($this->chatId, $this->telegramUserId);

            if ($this->destinationId !== null) {
                $allowlist->markJoined($this->destinationId, $this->telegramUserId, $this->username, 'Their join request was approved.');
            }
        } catch (TelegramApiException $e) {
            if ($e->type === TelegramErrorType::FloodControl) {
                $wait = max(1, (int) ($e->retryAfter ?? 30)) + 1;
                $posting->startCooldown($bot->id, $wait);
                $this->release($wait);

                return;
            }

            // Already approved / declined by an admin, request expired, or missing rights.
            Log::channel('telegram')->warning('Join request not approved', [
                'bot_id' => $bot->id,
                'chat_id' => $this->chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
