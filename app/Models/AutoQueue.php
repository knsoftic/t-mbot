<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Posts the Content Library to one channel at fixed daily times, in a loop.
 */
#[Fillable([
    'user_id', 'name', 'bot_id', 'destination_id', 'status', 'timezone', 'times', 'days', 'tag_id', 'order', 'created_by',
])]
class AutoQueue extends Model
{
    use BelongsToAccount;

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    /** Content order: key => label. */
    public const ORDERS = [
        'oldest' => 'Library order (oldest first)',
        'newest' => 'Newest first',
        'random' => 'Random',
    ];

    protected function casts(): array
    {
        return [
            'times' => 'array',
            'days' => 'array',
            'round' => 'integer',
            'next_run_at' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<TelegramBot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class, 'bot_id')->withTrashed();
    }

    /**
     * @return BelongsTo<TelegramDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(TelegramDestination::class, 'destination_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Tag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    /**
     * @return HasMany<AutoQueuePost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(AutoQueuePost::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
