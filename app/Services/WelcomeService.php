<?php

namespace App\Services;

use App\Enums\ParseMode;
use App\Enums\PostingStatus;
use App\Enums\PostTrigger;
use App\Jobs\ApproveJoinRequestJob;
use App\Models\BotSubscriber;
use App\Models\PostingLog;
use App\Models\Scopes\AccountScope;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Models\User;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramErrorType;
use App\Services\Telegram\TelegramService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Welcome DMs for people who join a channel or group.
 *
 * Telegram lets a bot open a private chat only with (a) someone who sent a
 * join request — for 5 minutes, until the request is handled — or (b)
 * someone who has pressed Start in the bot. Both cases are covered here;
 * other joiners cannot be messaged by any bot.
 */
class WelcomeService
{
    /** Updates the webhook subscribes to. */
    public const ALLOWED_UPDATES = ['message', 'chat_member', 'chat_join_request'];

    public const DEFAULT_MESSAGE = "👋 Welcome {first_name}!\n\nThanks for joining {chat_title}. We are glad to have you here.";

    public const PLACEHOLDERS = [
        '{first_name}' => 'First name',
        '{last_name}' => 'Last name',
        '{full_name}' => 'Full name',
        '{username}' => '@username (or the first name)',
        '{chat_title}' => 'Channel / group name',
    ];

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly PlanLimiter $limiter,
        private readonly PostingService $posting,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = ''): array
    {
        // $prefix: where the fields live in the validated data (e.g. "form." in Livewire).
        return [
            'welcome_enabled' => ['boolean'],
            'welcome_message' => ['nullable', "required_if:{$prefix}welcome_enabled,true", 'string', 'max:4000'],
            'welcome_parse_mode' => ['nullable', Rule::enum(ParseMode::class)],
            'welcome_button_text' => ['nullable', "required_with:{$prefix}welcome_button_url", 'string', 'max:64'],
            'welcome_button_url' => ['nullable', "required_with:{$prefix}welcome_button_text", 'url:https,http', 'max:2048'],
            'welcome_direct_joins' => ['boolean'],
            'auto_approve_requests' => ['boolean'],
        ];
    }

    /**
     * Save the welcome settings. When welcomes or approvals are switched on, the
     * bot is connected to receive updates; a connection problem is thrown after saving.
     *
     * @param  array<string, mixed>  $data  Validated with rules().
     */
    public function saveSettings(TelegramDestination $destination, array $data, ?User $user = null): TelegramDestination
    {
        $destination->fill([
            'welcome_enabled' => (bool) ($data['welcome_enabled'] ?? false),
            'welcome_message' => filled($data['welcome_message'] ?? null) ? trim($data['welcome_message']) : null,
            'welcome_parse_mode' => ($data['welcome_parse_mode'] ?? null) ?: null,
            'welcome_button_text' => filled($data['welcome_button_text'] ?? null) ? trim($data['welcome_button_text']) : null,
            'welcome_button_url' => filled($data['welcome_button_url'] ?? null) ? trim($data['welcome_button_url']) : null,
            'welcome_direct_joins' => (bool) ($data['welcome_direct_joins'] ?? false),
            'auto_approve_requests' => (bool) ($data['auto_approve_requests'] ?? false),
        ])->save();

        $this->audit->log('destination.welcome_updated', $destination, "Welcome settings of “{$destination->name}” saved", [
            'enabled' => $destination->welcome_enabled,
            'auto_approve' => $destination->auto_approve_requests,
        ], $user);

        $bot = $destination->bot;

        if (($destination->welcome_enabled || $destination->auto_approve_requests) && $bot && ! $bot->updates_connected_at) {
            $this->connect($bot);
        }

        return $destination;
    }

    /**
     * Point the bot's updates at this panel (Telegram webhook).
     *
     * @throws ValidationException
     */
    public function connect(TelegramBot $bot): void
    {
        $url = route('telegram.webhook', ['bot' => $bot->id]);

        if (! str_starts_with($url, 'https://')) {
            throw ValidationException::withMessages([
                'updates' => 'Telegram only sends updates to a public https:// address. Set APP_URL in .env to your https:// domain (this panel runs at '.url('/').').',
            ]);
        }

        $secret = $bot->webhook_secret ?: Str::random(48);

        try {
            $this->telegram->forBot($bot)->setWebhook($url, $secret, self::ALLOWED_UPDATES);
        } catch (TelegramApiException $e) {
            $bot->forceFill(['updates_connected_at' => null, 'updates_error' => mb_substr($e->friendlyMessage(), 0, 250)])->save();

            throw ValidationException::withMessages(['updates' => 'Telegram did not accept the connection: '.$e->friendlyMessage()]);
        }

        $bot->forceFill(['webhook_secret' => $secret, 'updates_connected_at' => CarbonImmutable::now(), 'updates_error' => null])->save();
        $this->audit->log('bot.updates_connected', $bot, "Bot “{$bot->name}” now receives updates (welcome messages)");
    }

    public function disconnect(TelegramBot $bot): void
    {
        try {
            $this->telegram->forBot($bot)->deleteWebhook();
        } catch (TelegramApiException $e) {
            Log::channel('telegram')->warning('deleteWebhook failed', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);
        }

        $bot->forceFill(['updates_connected_at' => null])->save();
    }

    /**
     * Create an invite link that asks to join; only these joiners can be messaged.
     *
     * @throws ValidationException
     */
    public function createJoinLink(TelegramDestination $destination, ?User $user = null): string
    {
        $bot = $destination->bot;

        if (! $bot || $bot->trashed()) {
            throw ValidationException::withMessages(['link' => 'The bot of this chat has been deleted.']);
        }

        try {
            $result = $this->telegram->forBot($bot)->createChatInviteLink($destination->apiChatId(), [
                'name' => Str::limit('Welcome · '.config('app.name'), 32, ''),
                'creates_join_request' => true,
            ]);
        } catch (TelegramApiException $e) {
            throw ValidationException::withMessages([
                'link' => $e->friendlyMessage().' The bot must be an administrator with the “Invite users via link” right.',
            ]);
        }

        $link = $result['invite_link'] ?? null;

        if (! is_string($link) || $link === '') {
            throw ValidationException::withMessages(['link' => 'Telegram did not return an invite link. Try again.']);
        }

        $destination->forceFill(['join_request_link' => $link])->save();
        $this->audit->log('destination.join_link_created', $destination, "Join-request link created for “{$destination->name}”", [], $user);

        return $link;
    }

    // ------------------------------------------------------------------
    // Incoming updates
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $update
     * @return string Outcome code (logs and tests).
     *
     * @throws TelegramApiException on flood control, so the caller can wait and retry.
     */
    public function handle(TelegramBot $bot, array $update): string
    {
        return match (true) {
            isset($update['chat_join_request']) => $this->onJoinRequest($bot, $update['chat_join_request']),
            isset($update['chat_member']) => $this->onChatMember($bot, $update['chat_member']),
            isset($update['message']) => $this->onPrivateMessage($bot, $update['message']),
            default => 'ignored',
        };
    }

    /**
     * @param  array<string, mixed>  $request  ChatJoinRequest
     */
    private function onJoinRequest(TelegramBot $bot, array $request): string
    {
        $destination = $this->destinationFor($bot, (array) ($request['chat'] ?? []));
        $from = (array) ($request['from'] ?? []);

        if (! $destination || ! isset($from['id'])) {
            return 'unknown_chat';
        }

        $outcome = 'no_welcome';

        // The DM must go out before the request is handled: approving ends the permission.
        if ($destination->welcome_enabled && $destination->isActive() && isset($request['user_chat_id'])) {
            $outcome = $this->sendWelcome($bot, $destination, (int) $request['user_chat_id'], $from);
        }

        if ($destination->auto_approve_requests) {
            ApproveJoinRequestJob::dispatch($bot->id, (string) $request['chat']['id'], (int) $from['id']);
            $outcome .= '+approve';
        }

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $change  ChatMemberUpdated
     */
    private function onChatMember(TelegramBot $bot, array $change): string
    {
        $old = $change['old_chat_member']['status'] ?? 'left';
        $new = $change['new_chat_member']['status'] ?? 'left';
        $isMember = $new === 'member' || ($new === 'restricted' && ($change['new_chat_member']['is_member'] ?? false));

        if (! $isMember || ! in_array($old, ['left', 'kicked'], true)) {
            return 'ignored';
        }

        // People approved from a join request were welcomed when they asked to join.
        if (($change['via_join_request'] ?? false) || ($change['invite_link']['creates_join_request'] ?? false)) {
            return 'via_join_request';
        }

        $member = (array) ($change['new_chat_member']['user'] ?? []);
        $destination = $this->destinationFor($bot, (array) ($change['chat'] ?? []));

        if (! $destination || ! $destination->welcome_enabled || ! $destination->isActive() || ! $destination->welcome_direct_joins || ! isset($member['id']) || ($member['is_bot'] ?? false)) {
            return 'ignored';
        }

        $subscribed = BotSubscriber::query()->withoutGlobalScope(AccountScope::class)
            ->where('bot_id', $bot->id)
            ->where('telegram_user_id', (int) $member['id'])
            ->whereNull('blocked_at')
            ->exists();

        // Telegram does not let a bot start a private chat with anyone else.
        if (! $subscribed) {
            return 'not_started';
        }

        return $this->sendWelcome($bot, $destination, (int) $member['id'], $member);
    }

    /**
     * Remember people who write to the bot privately (e.g. press Start).
     *
     * @param  array<string, mixed>  $message
     */
    private function onPrivateMessage(TelegramBot $bot, array $message): string
    {
        $from = (array) ($message['from'] ?? []);

        if (($message['chat']['type'] ?? null) !== 'private' || ! isset($from['id']) || ($from['is_bot'] ?? false)) {
            return 'ignored';
        }

        $subscriber = BotSubscriber::query()->withoutGlobalScope(AccountScope::class)
            ->firstOrNew(['bot_id' => $bot->id, 'telegram_user_id' => (int) $from['id']]);

        $subscriber->fill([
            'user_id' => $bot->user_id,
            'first_name' => isset($from['first_name']) ? mb_substr((string) $from['first_name'], 0, 255) : null,
            'last_name' => isset($from['last_name']) ? mb_substr((string) $from['last_name'], 0, 255) : null,
            'username' => $from['username'] ?? null,
            'blocked_at' => null,
        ]);
        $subscriber->started_at ??= CarbonImmutable::now();
        $subscriber->save();

        return 'subscriber';
    }

    // ------------------------------------------------------------------
    // Sending
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $user  Telegram User object of the new member.
     *
     * @throws TelegramApiException on flood control (nothing was sent; retry later).
     */
    public function sendWelcome(TelegramBot $bot, TelegramDestination $destination, int $chatId, array $user): string
    {
        $owner = $destination->user_id ? User::query()->find($destination->user_id) : null;

        // Welcome DMs count towards the account's plan, like every other message.
        if ($owner && $owner->isCustomer() && $this->limiter->sendingBlock($owner)) {
            Log::channel('campaign')->info('Welcome DM skipped: sending limit reached', ['destination_id' => $destination->id]);

            return 'limit_reached';
        }

        $text = $this->render($destination, $user);
        $log = PostingLog::query()->create([
            'user_id' => $destination->user_id,
            'bot_id' => $bot->id,
            'destination_id' => $destination->id,
            'message' => $text,
            'status' => PostingStatus::Processing,
            'trigger' => PostTrigger::Welcome,
            'is_test' => false,
            'chat_id' => (string) $chatId,
            'cycle' => 1,
            'attempts' => 1,
            'scheduled_at' => CarbonImmutable::now(),
            'api_called_at' => CarbonImmutable::now(),
        ]);

        $started = microtime(true);

        try {
            $result = $this->telegram->forBot($bot)->sendMessage($chatId, $text, $this->options($destination));
        } catch (TelegramApiException $e) {
            if ($e->type === TelegramErrorType::FloodControl) {
                $log->delete();
                $this->posting->startCooldown($bot->id, max(1, (int) ($e->retryAfter ?? 30)) + 1);

                throw $e;
            }

            if ($e->type === TelegramErrorType::Forbidden) {
                BotSubscriber::query()->withoutGlobalScope(AccountScope::class)
                    ->where('bot_id', $bot->id)->where('telegram_user_id', $chatId)
                    ->update(['blocked_at' => CarbonImmutable::now()]);
            }

            $log->forceFill([
                'status' => PostingStatus::Failed,
                'error_code' => $e->errorCode,
                'error_message' => mb_substr($e->type === TelegramErrorType::Forbidden
                    ? 'Telegram does not allow this bot to message the user (they never started the bot, blocked it, or the 5-minute join-request window passed).'
                    : $e->friendlyMessage(), 0, 1000),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'response_data' => TelegramService::sanitizeResponse($e->response),
            ])->save();

            return 'failed';
        }

        $log->forceFill([
            'status' => PostingStatus::Sent,
            'telegram_message_id' => isset($result['message_id']) ? (string) $result['message_id'] : null,
            'sent_at' => CarbonImmutable::now(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ])->save();

        return 'sent';
    }

    /**
     * The welcome text with the member's details filled in (escaped for the formatting).
     *
     * @param  array<string, mixed>  $user
     */
    public function render(TelegramDestination $destination, array $user): string
    {
        $first = trim((string) ($user['first_name'] ?? '')) ?: 'there';
        $last = trim((string) ($user['last_name'] ?? ''));

        $values = [
            '{first_name}' => $first,
            '{last_name}' => $last,
            '{full_name}' => trim($first.' '.$last),
            '{username}' => isset($user['username']) ? '@'.$user['username'] : $first,
            '{chat_title}' => (string) ($destination->telegram_title ?: $destination->name),
        ];

        $values = array_map(fn (string $value) => $this->escape($value, $destination->welcome_parse_mode), $values);
        $text = strtr((string) ($destination->welcome_message ?: self::DEFAULT_MESSAGE), $values);

        return mb_substr(trim($text), 0, 4096);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(TelegramDestination $destination): array
    {
        $options = array_filter(['parse_mode' => $destination->welcome_parse_mode?->value]);

        if ($destination->welcome_button_text && $destination->welcome_button_url) {
            $options['reply_markup'] = ['inline_keyboard' => [[['text' => $destination->welcome_button_text, 'url' => $destination->welcome_button_url]]]];
        }

        return $options;
    }

    private function escape(string $value, ?ParseMode $mode): string
    {
        return match ($mode) {
            ParseMode::Html => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ParseMode::MarkdownV2 => (string) preg_replace('/([_*\[\]()~`>#+\-=|{}.!\\\\])/', '\\\\$1', $value),
            null => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $chat
     */
    private function destinationFor(TelegramBot $bot, array $chat): ?TelegramDestination
    {
        if (! isset($chat['id'])) {
            return null;
        }

        $username = isset($chat['username']) ? '@'.$chat['username'] : null;

        return TelegramDestination::query()->withoutGlobalScope(AccountScope::class)
            ->where('bot_id', $bot->id)
            ->where(fn ($q) => $q->where('chat_id', (string) $chat['id'])->when($username, fn ($w) => $w->orWhere('chat_id', $username)))
            ->first();
    }
}
