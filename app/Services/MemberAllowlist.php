<?php

namespace App\Services;

use App\Enums\ImportRowStatus;
use App\Models\JoinRequest;
use App\Models\MemberImportRow;
use App\Models\Scopes\AccountScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * People on imported member lists: their join requests may be approved
 * automatically. Nobody else is ever approved because of a list.
 */
class MemberAllowlist
{
    public function isListed(int $destinationId, int $telegramUserId, ?string $username): bool
    {
        return $this->rows($destinationId, $telegramUserId, $username, onlyAutoApprove: true)->exists();
    }

    /**
     * The person is now in the chat (request approved, or joined): update lists and requests.
     */
    public function markJoined(int $destinationId, int $telegramUserId, ?string $username, string $reason): int
    {
        JoinRequest::query()->withoutGlobalScope(AccountScope::class)
            ->where('destination_id', $destinationId)
            ->where('telegram_user_id', $telegramUserId)
            ->where('status', JoinRequest::PENDING)
            ->update(['status' => JoinRequest::APPROVED, 'decided_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);

        return $this->rows($destinationId, $telegramUserId, $username, onlyAutoApprove: false)->update([
            'status' => ImportRowStatus::Added,
            'telegram_user_id' => $telegramUserId,
            'reason' => $reason,
            'processed_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @return Builder<MemberImportRow>
     */
    private function rows(int $destinationId, int $telegramUserId, ?string $username, bool $onlyAutoApprove): Builder
    {
        $username = $username ? strtolower(ltrim($username, '@')) : null;

        return MemberImportRow::query()
            ->whereIn('status', ImportRowStatus::allowlisted())
            ->whereHas('import', fn (Builder $q) => $q->withoutGlobalScope(AccountScope::class)
                ->where('destination_id', $destinationId)
                ->when($onlyAutoApprove, fn (Builder $w) => $w->where('auto_approve', true)))
            ->where(fn (Builder $q) => $q->where('telegram_user_id', $telegramUserId)
                ->when($username, fn (Builder $w) => $w->orWhere(fn (Builder $u) => $u->whereNull('telegram_user_id')->where('username', $username))));
    }
}
