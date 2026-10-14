<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone asked to join a channel / group (through a join-request link).
 */
#[Fillable(['user_id', 'destination_id', 'bot_id', 'telegram_user_id', 'username', 'first_name', 'status', 'requested_at', 'decided_at'])]
class JoinRequest extends Model
{
    use BelongsToAccount;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<TelegramDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(TelegramDestination::class, 'destination_id')->withTrashed();
    }
}
