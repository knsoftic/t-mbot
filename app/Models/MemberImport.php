<?php

namespace App\Models;

use App\Enums\ImportRowStatus;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An uploaded list of Telegram users to bring into a channel / group.
 */
#[Fillable([
    'user_id', 'destination_id', 'bot_id', 'file_name', 'status', 'send_invites', 'auto_approve',
    'invite_message', 'invite_link', 'error', 'started_at', 'finished_at', 'created_by',
])]
class MemberImport extends Model
{
    use BelongsToAccount;

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'send_invites' => 'boolean',
            'auto_approve' => 'boolean',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<TelegramDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(TelegramDestination::class, 'destination_id')->withTrashed();
    }

    /**
     * @return BelongsTo<TelegramBot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class, 'bot_id')->withTrashed();
    }

    /**
     * @return HasMany<MemberImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(MemberImportRow::class);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::PENDING, self::PROCESSING], true);
    }

    /**
     * Rows per status, every status present (0 when none).
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = $this->rows()->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return collect(ImportRowStatus::values())->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)])->all();
    }
}
