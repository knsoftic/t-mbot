<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Enums\ConnectionStatus;
use App\Enums\DestinationStatus;
use App\Enums\DestinationType;
use App\Events\DestinationPermissionFailed;
use App\Jobs\TestDestinationJob;
use App\Models\Campaign;
use App\Models\Scopes\AccountScope;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Models\User;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramService;
use App\Support\Accounts;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DestinationService
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly PlanLimiter $limiter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(?TelegramDestination $destination = null): array
    {
        return [
            'bot_id' => ['required', 'integer', Accounts::exists('telegram_bots')],
            'name' => ['required', 'string', 'max:120'],
            'chat_id' => ['required', 'string', 'max:100', 'regex:/^(-?\d{3,}|@?[A-Za-z][A-Za-z0-9_]{3,}|(https?:\/\/)?t\.me\/[A-Za-z][A-Za-z0-9_]{3,})$/'],
            'type' => ['required', Rule::enum(DestinationType::class)],
            'status' => ['required', Rule::enum(DestinationStatus::class)],
            'is_test_chat' => ['boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return ['chat_id.regex' => 'Enter a numeric chat ID (e.g. -1001234567890) or a public @username.'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $user = null): TelegramDestination
    {
        $data['chat_id'] = $this->normalizeInput($data['chat_id']);
        $this->assertUnique((int) $data['bot_id'], $data['chat_id']);

        // A channel or group belongs to the account that owns its bot.
        $ownerId = $this->botOwner((int) $data['bot_id']);
        $this->limiter->assertCanAdd($ownerId, self::limitFor($data['type']), 'chat_id');

        $destination = TelegramDestination::create($data + [
            'user_id' => $ownerId,
            'username' => str_starts_with($data['chat_id'], '@') ? ltrim($data['chat_id'], '@') : null,
            'created_by' => $user?->id,
        ]);

        $this->audit->log('destination.created', $destination, "Destination \"{$destination->name}\" added", ['chat_id' => $destination->chat_id], $user);

        return $destination;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TelegramDestination $destination, array $data, ?User $user = null): TelegramDestination
    {
        $data['chat_id'] = $this->normalizeInput($data['chat_id']);
        $this->assertUnique((int) $data['bot_id'], $data['chat_id'], $destination);

        $destination->fill($data);

        if ($destination->isDirty('bot_id')) {
            $destination->user_id = $this->botOwner((int) $data['bot_id']);
        }

        // Turning a group into a channel (or the other way round) counts against the other limit.
        $otherLimit = self::limitFor($destination->type) !== self::limitFor($destination->getOriginal('type'));

        if ($destination->isDirty('user_id') || ($destination->isDirty('type') && $otherLimit)) {
            $this->limiter->assertCanAdd($destination->user_id, self::limitFor($destination->type), 'type');
        }

        if ($destination->isDirty(['chat_id', 'bot_id'])) {
            $destination->connection_status = ConnectionStatus::Unknown;
            $destination->bot_can_post = null;
            $destination->bot_is_admin = null;
        }

        $destination->save();
        $this->audit->log('destination.updated', $destination, "Destination \"{$destination->name}\" edited", $this->audit->changes($destination), $user);

        return $destination;
    }

    public function delete(TelegramDestination $destination, ?User $user = null): void
    {
        $inUse = Campaign::query()
            ->where(fn ($q) => $q->where('destination_id', $destination->id)->orWhere('test_destination_id', $destination->id))
            ->whereIn('status', [CampaignStatus::Scheduled, CampaignStatus::Running, CampaignStatus::Paused])
            ->pluck('name');

        if ($inUse->isNotEmpty()) {
            throw ValidationException::withMessages(['destination' => 'Used by active campaigns: '.$inUse->implode(', ').'. Stop them first.']);
        }

        // Free the (bot_id, chat_id) unique key so the chat can be connected again later.
        $destination->forceFill(['chat_id' => mb_substr($destination->chat_id, 0, 70).'#deleted-'.$destination->id])->saveQuietly();
        $destination->delete();
        $this->audit->log('destination.deleted', $destination, "Destination \"{$destination->name}\" deleted", [], $user);
    }

    /**
     * Queue a connection test; the UI polls connection_status for the result.
     */
    public function queueTest(TelegramDestination $destination, bool $sendMessage = true, ?User $user = null): void
    {
        $destination->forceFill(['connection_status' => ConnectionStatus::Testing, 'last_error' => null])->save();
        TestDestinationJob::dispatch($destination->id, $sendMessage);
        $this->audit->log('destination.tested', $destination, "Connection test queued for \"{$destination->name}\"", ['send_message' => $sendMessage], $user);
    }

    /**
     * Check the bot can see the chat and post in it; optionally send a test message.
     *
     * @return array{ok: bool, message: string}
     */
    public function verify(TelegramDestination $destination, bool $sendMessage = true): array
    {
        $bot = $destination->bot;

        if (! $bot || $bot->trashed()) {
            return $this->markFailed($destination, 'The bot for this destination has been deleted.');
        }

        $client = $this->telegram->forBot($bot);

        try {
            $me = $client->getMe();
            $chat = $client->getChat($destination->apiChatId());
        } catch (TelegramApiException $e) {
            if ($e->migrateToChatId) {
                $destination->forceFill(['chat_id' => (string) $e->migrateToChatId, 'type' => DestinationType::Supergroup])->save();

                return $this->verify($destination->refresh(), $sendMessage);
            }

            return $this->markFailed($destination, $e->friendlyMessage());
        }

        $type = DestinationType::tryFrom((string) ($chat['type'] ?? ''));

        if ($type === null) {
            return $this->markFailed($destination, 'This chat is a private chat. Only channels, groups and supergroups are supported.');
        }

        try {
            $member = $client->getChatMember($chat['id'], (int) $me['id']);
        } catch (TelegramApiException $e) {
            return $this->markFailed($destination, $e->friendlyMessage());
        }

        $duplicate = TelegramDestination::query()
            ->where('bot_id', $destination->bot_id)
            ->where('chat_id', (string) $chat['id'])
            ->whereKeyNot($destination->id)
            ->first();

        if ($duplicate) {
            return $this->markFailed($destination, "This chat is already connected to the same bot as \"{$duplicate->name}\".");
        }

        $status = (string) ($member['status'] ?? 'left');
        $isAdmin = in_array($status, ['creator', 'administrator'], true);
        $canPost = match ($type) {
            DestinationType::Channel => $status === 'creator' || ($status === 'administrator' && ($member['can_post_messages'] ?? false)),
            default => in_array($status, ['creator', 'administrator', 'member'], true) || ($status === 'restricted' && ($member['can_send_messages'] ?? false)),
        };

        $destination->forceFill([
            'chat_id' => (string) $chat['id'],
            'type' => $type,
            'telegram_title' => $chat['title'] ?? null,
            'username' => $chat['username'] ?? $destination->username,
            'bot_is_admin' => $isAdmin,
            'bot_can_post' => $canPost,
            'last_checked_at' => CarbonImmutable::now(),
        ]);

        if (! $canPost) {
            $destination->save();

            return $this->markFailed($destination, $type === DestinationType::Channel
                ? 'The bot is not an administrator with the "Post messages" permission in this channel. Open the channel → Administrators → add the bot and enable "Post messages".'
                : "The bot cannot send messages in this group (member status: {$status}). Add the bot to the group and allow it to send messages.");
        }

        if ($sendMessage) {
            try {
                $text = str_replace('{app}', $this->settings->appName(), (string) $this->settings->get('telegram.test_message'));
                $client->sendMessage($chat['id'], $text);
            } catch (TelegramApiException $e) {
                $destination->save();

                return $this->markFailed($destination, $e->friendlyMessage());
            }
        }

        $destination->forceFill(['connection_status' => ConnectionStatus::Connected, 'last_error' => null])->save();

        return ['ok' => true, 'message' => $sendMessage ? 'Test message sent successfully.' : 'The bot can post in this chat.'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function markFailed(TelegramDestination $destination, string $message): array
    {
        $wasConnected = $destination->connection_status === ConnectionStatus::Connected;

        $destination->forceFill([
            'connection_status' => ConnectionStatus::Failed,
            'last_error' => mb_substr($message, 0, 250),
            'last_checked_at' => CarbonImmutable::now(),
        ])->save();

        if ($wasConnected) {
            event(new DestinationPermissionFailed($destination, $message));
        }

        return ['ok' => false, 'message' => $message];
    }

    /**
     * The plan limit a destination type counts against.
     */
    public static function limitFor(DestinationType|string|null $type): string
    {
        $type = $type instanceof DestinationType ? $type : DestinationType::tryFrom((string) $type);

        return $type === DestinationType::Channel ? 'max_channels' : 'max_groups';
    }

    private function botOwner(int $botId): ?int
    {
        $owner = TelegramBot::withTrashed()->withoutGlobalScope(AccountScope::class)->whereKey($botId)->value('user_id');

        return $owner === null ? null : (int) $owner;
    }

    private function normalizeInput(string $chatId): string
    {
        return TelegramDestination::normalizeChatId($chatId);
    }

    private function assertUnique(int $botId, string $chatId, ?TelegramDestination $ignore = null): void
    {
        $exists = TelegramDestination::query()
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['chat_id' => 'This chat is already connected to the selected bot.']);
        }
    }
}
