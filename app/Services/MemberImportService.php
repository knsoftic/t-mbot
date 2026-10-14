<?php

namespace App\Services;

use App\Enums\ImportRowStatus;
use App\Enums\PostingStatus;
use App\Enums\PostTrigger;
use App\Jobs\ProcessMemberImportJob;
use App\Models\BotSubscriber;
use App\Models\JoinRequest;
use App\Models\MemberImport;
use App\Models\MemberImportRow;
use App\Models\PostingLog;
use App\Models\Scopes\AccountScope;
use App\Models\TelegramDestination;
use App\Models\User;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramErrorType;
use App\Services\Telegram\TelegramService;
use App\Support\SpreadsheetReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Member import.
 *
 * The Bot API cannot add anyone to a chat, and adding people in bulk through
 * personal accounts breaks Telegram's terms. So a list is handled the way
 * Telegram allows: members are recognised, pending join requests from listed
 * people are approved, people who started the bot get a private invite, and
 * everyone else is approved automatically once they ask to join with the
 * join-request link. Requests from people who are not on a list are left alone.
 */
class MemberImportService
{
    public const MAX_ROWS = 10000;

    public const DEFAULT_INVITE = "Hi {first_name}! 👋\n\nYou are invited to join {chat_title}. Tap the link below and send a join request — it will be approved right away:\n{link}";

    /** Pause between Bot API calls (≈20 per second per import). */
    private const PAUSE_MICROSECONDS = 50_000;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly WelcomeService $welcome,
        private readonly PlanLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------
    // Reading the list
    // ------------------------------------------------------------------

    /**
     * Parse an uploaded list into rows ready to store.
     *
     * @return list<array{row_number: int, input: string, telegram_user_id: int|null, username: string|null, status: string, reason: string|null}>
     *
     * @throws ValidationException
     */
    public function parse(string $path, string $extension): array
    {
        try {
            $sheet = SpreadsheetReader::rows($path, $extension, self::MAX_ROWS + 1);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        // A header row names the columns; without one the first column is used.
        $header = array_map(fn ($cell) => strtolower(preg_replace('/[^a-z_]/i', '', (string) $cell)), $sheet[0] ?? []);
        $idColumn = $this->findColumn($header, ['userid', 'user_id', 'id', 'telegramid', 'telegram_id']);
        $nameColumn = $this->findColumn($header, ['username', 'user_name', 'handle', 'telegram', 'telegramusername']);
        $hasHeader = $idColumn !== null || $nameColumn !== null;

        $rows = [];
        $seen = [];

        foreach ($sheet as $index => $cells) {
            if ($hasHeader && $index === 0) {
                continue;
            }

            if ($hasHeader) {
                $name = trim((string) ($nameColumn !== null ? ($cells[$nameColumn] ?? '') : ''));

                // A bare word in the username column is a username.
                if (preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $name)) {
                    $name = '@'.$name;
                }

                $input = trim(trim((string) ($idColumn !== null ? ($cells[$idColumn] ?? '') : '')).' '.$name);
            } else {
                $input = trim((string) collect($cells)->first(fn ($cell) => trim((string) $cell) !== ''));
            }

            if ($input === '') {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => 'The list has more than '.number_format(self::MAX_ROWS).' people. Split it into smaller files.']);
            }

            $row = ['row_number' => $index + 1, 'input' => mb_substr($input, 0, 255)] + $this->normalize($input);
            $key = $row['telegram_user_id'] ? 'id:'.$row['telegram_user_id'] : ($row['username'] ? 'u:'.$row['username'] : null);

            if ($key !== null && isset($seen[$key])) {
                $row['status'] = ImportRowStatus::Skipped->value;
                $row['reason'] = 'Duplicate of row '.$seen[$key].'.';
            } elseif ($key !== null) {
                $seen[$key] = $row['row_number'];
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'No usernames or user IDs were found in the file.']);
        }

        return $rows;
    }

    /**
     * @return array{telegram_user_id: int|null, username: string|null, status: string, reason: string|null}
     */
    public function normalize(string $input): array
    {
        $pending = ImportRowStatus::Pending->value;
        $skipped = ImportRowStatus::Skipped->value;

        // "+92 300 1234567": a phone number, not an ID followed by other text.
        if (preg_match('/^\+[\d\s().-]{7,}$/', trim($input))) {
            return ['telegram_user_id' => null, 'username' => null, 'status' => $skipped, 'reason' => 'Phone numbers cannot be used: bots can only work with usernames or user IDs.'];
        }

        $parts = preg_split('/\s+/', trim($input)) ?: [];
        $id = null;
        $username = null;

        // A bare word is a username only when it is the whole cell; "John Smith" is a name.
        $single = count($parts) === 1;

        foreach ($parts as $part) {
            $part = trim($part, " \t,;");

            if (preg_match('/^\d{5,20}$/', $part)) {
                $id ??= (int) $part;
            } elseif (preg_match('#^(?:https?://)?(?:t\.me|telegram\.me)/([A-Za-z][A-Za-z0-9_]{3,31})/?$#i', $part, $m)
                || preg_match($single ? '/^@?([A-Za-z][A-Za-z0-9_]{3,31})$/' : '/^@([A-Za-z][A-Za-z0-9_]{3,31})$/', $part, $m)) {
                $username ??= strtolower($m[1]);
            }
        }

        if ($id !== null || $username !== null) {
            return ['telegram_user_id' => $id, 'username' => $username, 'status' => $pending, 'reason' => null];
        }

        return ['telegram_user_id' => null, 'username' => null, 'status' => $skipped, 'reason' => match (true) {
            (bool) preg_match('/^-\d+$/', trim($input)) => 'This is a group / channel ID, not a user.',
            default => 'Not a valid Telegram username or user ID.',
        }];
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $names
     */
    private function findColumn(array $header, array $names): ?int
    {
        foreach ($header as $index => $cell) {
            if (in_array(str_replace('_', '', $cell), array_map(fn ($n) => str_replace('_', '', $n), $names), true)) {
                return $index;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Creating and running an import
    // ------------------------------------------------------------------

    /**
     * @param  array{send_invites?: bool, auto_approve?: bool, invite_message?: string|null}  $options
     *
     * @throws ValidationException
     */
    public function create(TelegramDestination $destination, UploadedFile $file, array $options, ?User $user = null): MemberImport
    {
        $bot = $destination->bot;

        if (! $bot || $bot->trashed() || ! $bot->isActive()) {
            throw ValidationException::withMessages(['destination_id' => 'The bot of this chat is deleted or disabled.']);
        }

        $rows = $this->parse($file->getRealPath(), $file->getClientOriginalExtension() ?: $file->extension());

        // Everything after the check runs on the join-request link of the chat.
        $link = $destination->join_request_link ?: $this->welcome->createJoinLink($destination, $user);

        $import = DB::transaction(function () use ($destination, $bot, $file, $options, $user, $rows, $link) {
            $import = MemberImport::query()->create([
                'user_id' => $destination->user_id,
                'destination_id' => $destination->id,
                'bot_id' => $bot->id,
                'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'status' => MemberImport::PENDING,
                'send_invites' => (bool) ($options['send_invites'] ?? true),
                'auto_approve' => (bool) ($options['auto_approve'] ?? true),
                'invite_message' => filled($options['invite_message'] ?? null) ? trim($options['invite_message']) : null,
                'invite_link' => $link,
                'created_by' => $user?->id,
            ]);

            $now = CarbonImmutable::now();

            foreach (array_chunk($rows, 500) as $chunk) {
                MemberImportRow::query()->insert(array_map(fn (array $row) => $row + [
                    'member_import_id' => $import->id,
                    'processed_at' => $row['status'] === ImportRowStatus::Skipped->value ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            return $import;
        });

        $this->audit->log('member_import.created', $import, "Member list “{$import->file_name}” (".count($rows)." people) imported for “{$destination->name}”", [], $user);
        ProcessMemberImportJob::dispatch($import->id);

        return $import;
    }

    /**
     * Check again everyone who is still waiting or failed (e.g. after they started the bot).
     */
    public function recheck(MemberImport $import, ?User $user = null): int
    {
        $import->rows()
            ->whereIn('status', [ImportRowStatus::Waiting, ImportRowStatus::Failed])
            ->update(['status' => ImportRowStatus::Pending, 'reason' => null, 'processed_at' => null, 'updated_at' => now()]);

        // Includes rows left over when an earlier run stopped.
        $count = $import->rows()->where('status', ImportRowStatus::Pending)->count();

        if ($count > 0) {
            $import->forceFill(['status' => MemberImport::PENDING, 'error' => null, 'finished_at' => null])->save();
            ProcessMemberImportJob::dispatch($import->id);
            $this->audit->log('member_import.rechecked', $import, "Member import #{$import->id}: {$count} people checked again", [], $user);
        }

        return $count;
    }

    /**
     * Work through pending rows for up to $seconds.
     *
     * @return bool true when rows are left (call again).
     *
     * @throws TelegramApiException on flood control (wait and call again).
     */
    public function process(MemberImport $import, int $seconds = 40): bool
    {
        $import->loadMissing(['destination', 'bot']);
        $destination = $import->destination;
        $bot = $import->bot;

        if (! $destination || $destination->trashed() || ! $bot || $bot->trashed() || ! $bot->isActive()) {
            $this->finish($import, MemberImport::FAILED, 'The chat or its bot was deleted or disabled.');

            return false;
        }

        if ($import->status !== MemberImport::PROCESSING) {
            $import->forceFill(['status' => MemberImport::PROCESSING, 'started_at' => $import->started_at ?? CarbonImmutable::now()])->save();
        }

        $client = $this->telegram->forBot($bot);
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            $rows = $import->rows()->where('status', ImportRowStatus::Pending)->orderBy('id')->limit(25)->get();

            if ($rows->isEmpty()) {
                $this->finish($import, MemberImport::COMPLETED);

                return false;
            }

            foreach ($rows as $row) {
                try {
                    $this->processRow($import, $row, $client);
                } catch (TelegramApiException $e) {
                    // Flood control or a passing network problem: the job waits and continues.
                    if (in_array($e->type, [TelegramErrorType::FloodControl, TelegramErrorType::Network, TelegramErrorType::Timeout, TelegramErrorType::ServerError], true)) {
                        throw $e;
                    }

                    // The bot lost access to the chat: stop instead of failing every row.
                    if ($e->type->isBotFailure() || $e->type->isPermissionFailure()) {
                        $this->finish($import, MemberImport::FAILED, mb_substr($e->friendlyMessage(), 0, 250));

                        return false;
                    }

                    $this->mark($row, ImportRowStatus::Failed, $e->friendlyMessage());
                }
            }
        }

        return true;
    }

    /**
     * @throws TelegramApiException
     */
    private function processRow(MemberImport $import, MemberImportRow $row, TelegramService $client): void
    {
        $destination = $import->destination;
        $subscriber = $this->subscriber($import, $row);
        $userId = $row->telegram_user_id ?? $subscriber?->telegram_user_id;

        if ($userId !== null && $row->telegram_user_id === null) {
            $row->telegram_user_id = $userId;
        }

        if ($userId !== null && $userId === (int) $import->bot->telegram_id) {
            $this->mark($row, ImportRowStatus::Skipped, 'This is the bot itself.');

            return;
        }

        // 1. Already in the chat, or banned from it?
        if ($userId !== null) {
            $status = $this->memberStatus($client, $destination, $userId);

            if ($status === 'member') {
                $this->mark($row, ImportRowStatus::AlreadyMember, 'Already in the chat.');

                return;
            }

            if ($status === 'kicked') {
                $this->mark($row, ImportRowStatus::Skipped, 'Banned from this chat. Unban them in Telegram first if they should join.');

                return;
            }
        }

        // 2. A join request is already waiting: approve it (they asked to join).
        $request = $this->pendingRequest($destination, $userId, $row->username);

        if ($request && $import->auto_approve) {
            $client->approveChatJoinRequest($destination->apiChatId(), $request->telegram_user_id);
            usleep(self::PAUSE_MICROSECONDS);
            $request->forceFill(['status' => JoinRequest::APPROVED, 'decided_at' => CarbonImmutable::now()])->save();
            $row->telegram_user_id ??= $request->telegram_user_id;
            $this->mark($row, ImportRowStatus::Added, 'Their join request was approved.');

            return;
        }

        // 3. They started the bot: send a private invite.
        if ($import->send_invites && $userId !== null && $subscriber && ! $subscriber->blocked_at) {
            $this->invite($import, $row, $client, $subscriber);

            return;
        }

        // 4. Nobody else can be contacted by a bot.
        $this->mark($row, ImportRowStatus::Waiting, match (true) {
            ! $import->auto_approve => 'A bot cannot add or message them. Share the join-request link with them.',
            $userId === null => 'A bot cannot add or message them (unknown user ID). When @'.$row->username.' asks to join with the join-request link, the request is approved automatically.',
            default => 'A bot cannot add or message them (they never started the bot). When they ask to join with the join-request link, the request is approved automatically.',
        });
    }

    /**
     * "member", "kicked" or "left" (unknown users count as "left").
     *
     * @throws TelegramApiException
     */
    private function memberStatus(TelegramService $client, TelegramDestination $destination, int $userId): string
    {
        try {
            $member = $client->getChatMember($destination->apiChatId(), $userId);
        } catch (TelegramApiException $e) {
            if ($e->type === TelegramErrorType::BadRequest) {
                return 'left'; // user not found / never seen
            }

            throw $e;
        } finally {
            usleep(self::PAUSE_MICROSECONDS);
        }

        $status = (string) ($member['status'] ?? 'left');

        return match (true) {
            in_array($status, ['creator', 'administrator', 'member'], true),
            $status === 'restricted' && ($member['is_member'] ?? false) => 'member',
            $status === 'kicked' => 'kicked',
            default => 'left',
        };
    }

    /**
     * @throws TelegramApiException on flood control.
     */
    private function invite(MemberImport $import, MemberImportRow $row, TelegramService $client, BotSubscriber $subscriber): void
    {
        $owner = $import->user_id ? User::query()->find($import->user_id) : null;

        if ($owner && $owner->isCustomer() && $this->limiter->sendingBlock($owner)) {
            $this->mark($row, ImportRowStatus::Waiting, 'Not messaged: your plan’s posting limit is used up. Use “Check again” after it resets.');

            return;
        }

        $text = strtr($import->invite_message ?: self::DEFAULT_INVITE, [
            '{first_name}' => $subscriber->first_name ?: 'there',
            '{chat_title}' => $import->destination->telegram_title ?: $import->destination->name,
            '{link}' => (string) $import->invite_link,
        ]);

        $log = PostingLog::query()->create([
            'user_id' => $import->user_id,
            'bot_id' => $import->bot_id,
            'destination_id' => $import->destination_id,
            'message' => $text,
            'status' => PostingStatus::Processing,
            'trigger' => PostTrigger::Invite,
            'chat_id' => (string) $subscriber->telegram_user_id,
            'cycle' => 1,
            'attempts' => 1,
            'scheduled_at' => CarbonImmutable::now(),
            'api_called_at' => CarbonImmutable::now(),
        ]);

        try {
            $result = $client->sendMessage($subscriber->telegram_user_id, $text, ['link_preview_options' => ['is_disabled' => true]]);
        } catch (TelegramApiException $e) {
            if ($e->type === TelegramErrorType::FloodControl) {
                $log->delete();

                throw $e;
            }

            $log->forceFill(['status' => PostingStatus::Failed, 'error_code' => $e->errorCode, 'error_message' => mb_substr($e->friendlyMessage(), 0, 1000)])->save();

            if ($e->type === TelegramErrorType::Forbidden) {
                $subscriber->forceFill(['blocked_at' => CarbonImmutable::now()])->save();
                $this->mark($row, ImportRowStatus::Waiting, 'They blocked the bot, so no invite was sent. Their request is approved automatically if they ask to join.');

                return;
            }

            $this->mark($row, ImportRowStatus::Failed, 'Invite not sent: '.$e->friendlyMessage());

            return;
        } finally {
            usleep(self::PAUSE_MICROSECONDS);
        }

        $log->forceFill(['status' => PostingStatus::Sent, 'sent_at' => CarbonImmutable::now(), 'telegram_message_id' => isset($result['message_id']) ? (string) $result['message_id'] : null])->save();
        $this->mark($row, ImportRowStatus::Invited, 'Invite sent by private message. Their join request is approved automatically.');
    }

    private function subscriber(MemberImport $import, MemberImportRow $row): ?BotSubscriber
    {
        if ($row->telegram_user_id === null && $row->username === null) {
            return null;
        }

        return BotSubscriber::query()->withoutGlobalScope(AccountScope::class)
            ->where('bot_id', $import->bot_id)
            ->where(fn ($q) => $row->telegram_user_id !== null
                ? $q->where('telegram_user_id', $row->telegram_user_id)
                : $q->whereRaw('lower(username) = ?', [$row->username]))
            ->first();
    }

    private function pendingRequest(TelegramDestination $destination, ?int $userId, ?string $username): ?JoinRequest
    {
        if ($userId === null && $username === null) {
            return null;
        }

        return JoinRequest::query()->withoutGlobalScope(AccountScope::class)
            ->where('destination_id', $destination->id)
            ->where('status', JoinRequest::PENDING)
            ->where(fn ($q) => $userId !== null ? $q->where('telegram_user_id', $userId) : $q->whereRaw('lower(username) = ?', [$username]))
            ->first();
    }

    private function mark(MemberImportRow $row, ImportRowStatus $status, string $reason): void
    {
        $row->forceFill(['status' => $status, 'reason' => mb_substr($reason, 0, 500), 'processed_at' => CarbonImmutable::now()])->save();
    }

    private function finish(MemberImport $import, string $status, ?string $error = null): void
    {
        $import->forceFill(['status' => $status, 'error' => $error, 'finished_at' => CarbonImmutable::now()])->save();
    }
}
