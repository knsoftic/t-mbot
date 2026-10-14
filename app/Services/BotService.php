<?php

namespace App\Services;

use App\Enums\BotStatus;
use App\Enums\CampaignStatus;
use App\Enums\ConnectionStatus;
use App\Events\BotConnectionFailed;
use App\Models\Scopes\AccountScope;
use App\Models\TelegramBot;
use App\Models\User;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramErrorType;
use App\Services\Telegram\TelegramService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BotService
{
    /**
     * Telegram token format: "<numeric bot id>:<35+ character secret>".
     */
    public const TOKEN_PATTERN = '/^\d{5,}:[A-Za-z0-9_-]{30,}$/';

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly AuditLogger $audit,
        private readonly PlanLimiter $limiter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(?TelegramBot $bot = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'token' => [$bot ? 'nullable' : 'required', 'string', 'max:200', 'regex:'.self::TOKEN_PATTERN],
            'status' => ['required', Rule::enum(BotStatus::class)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return ['token.regex' => 'This does not look like a bot token. Tokens look like 123456789:AAH... (copy it from @BotFather).'];
    }

    /**
     * @param  array{name: string, token: string, status: string, description?: string|null}  $data
     */
    public function create(array $data, ?User $user = null): TelegramBot
    {
        $token = trim($data['token']);
        $this->limiter->assertCanAdd($user, 'max_bots', 'token');
        $this->assertTokenIsNew($token);

        $bot = new TelegramBot([
            'user_id' => $user?->id,
            'name' => $data['name'],
            'token' => $token,
            'status' => $data['status'],
            'description' => $data['description'] ?? null,
            'created_by' => $user?->id,
        ]);
        $bot->connection_status = ConnectionStatus::Unknown;
        $bot->save();

        $this->audit->log('bot.created', $bot, "Bot \"{$bot->name}\" added", [], $user);
        $this->test($bot);

        return $bot->refresh();
    }

    /**
     * @param  array{name: string, token?: string|null, status: string, description?: string|null}  $data
     */
    public function update(TelegramBot $bot, array $data, ?User $user = null): TelegramBot
    {
        $bot->fill([
            'name' => $data['name'],
            'status' => $data['status'],
            'description' => $data['description'] ?? null,
        ]);

        $tokenChanged = false;

        if (! empty($data['token']) && trim($data['token']) !== $bot->token) {
            $this->assertTokenIsNew(trim($data['token']), $bot);
            $bot->token = trim($data['token']);
            $bot->connection_status = ConnectionStatus::Unknown;
            $tokenChanged = true;
        }

        $bot->save();
        $this->audit->log('bot.updated', $bot, "Bot \"{$bot->name}\" edited", $this->audit->changes($bot), $user);

        if ($tokenChanged) {
            $this->test($bot);
        }

        return $bot->refresh();
    }

    /**
     * Verify the token with getMe and refresh the bot's identity.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(TelegramBot $bot): array
    {
        try {
            $me = $this->telegram->forBot($bot)->getMe();

            $bot->forceFill([
                'username' => $me['username'] ?? $bot->username,
                'telegram_id' => $me['id'] ?? $bot->telegram_id,
                'connection_status' => ConnectionStatus::Connected,
                'last_error' => null,
                'last_checked_at' => CarbonImmutable::now(),
            ])->save();

            return ['ok' => true, 'message' => 'Connected as @'.($me['username'] ?? '?').'.'];
        } catch (TelegramApiException $e) {
            $wasConnected = $bot->connection_status === ConnectionStatus::Connected;

            $bot->forceFill([
                'connection_status' => ConnectionStatus::Failed,
                'last_error' => mb_substr($e->friendlyMessage(), 0, 250),
                'last_checked_at' => CarbonImmutable::now(),
            ])->save();

            if ($wasConnected && $e->type === TelegramErrorType::InvalidToken) {
                event(new BotConnectionFailed($bot, $e->friendlyMessage()));
            }

            return ['ok' => false, 'message' => $e->friendlyMessage()];
        }
    }

    public function setStatus(TelegramBot $bot, BotStatus $status, ?User $user = null): void
    {
        $bot->update(['status' => $status]);
        $this->audit->log('bot.'.($status === BotStatus::Active ? 'enabled' : 'disabled'), $bot, "Bot \"{$bot->name}\" {$status->value}", [], $user);
    }

    public function delete(TelegramBot $bot, ?User $user = null): void
    {
        $inUse = $bot->campaigns()
            ->whereIn('status', [CampaignStatus::Scheduled, CampaignStatus::Running, CampaignStatus::Paused])
            ->pluck('name');

        if ($inUse->isNotEmpty()) {
            throw ValidationException::withMessages(['bot' => 'This bot is used by active campaigns: '.$inUse->implode(', ').'. Stop them first.']);
        }

        DB::transaction(function () use ($bot) {
            // Free the token so the same bot can be added again later.
            $bot->forceFill(['token_hash' => hash('sha256', 'deleted:'.$bot->id.':'.$bot->token_hash)])->saveQuietly();
            $bot->destinations()->each(function ($destination) {
                $destination->forceFill(['chat_id' => mb_substr($destination->chat_id, 0, 70).'#deleted-'.$destination->id])->saveQuietly();
                $destination->delete();
            });
            $bot->delete();
        });

        $this->audit->log('bot.deleted', $bot, "Bot \"{$bot->name}\" deleted", [], $user);
    }

    private function assertTokenIsNew(string $token, ?TelegramBot $ignore = null): void
    {
        // Checked across all accounts: one Telegram bot can only be in one account.
        $exists = TelegramBot::withTrashed()
            ->withoutGlobalScope(AccountScope::class)
            ->where('token_hash', TelegramBot::hashToken($token))
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['token' => 'This bot token is already registered.']);
        }
    }
}
