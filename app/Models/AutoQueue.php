<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Posts the Content Library to one channel at set times of day, or every N
 * seconds / minutes / hours, in a loop.
 */
#[Fillable([
    'user_id', 'name', 'bot_id', 'destination_id', 'status', 'timezone', 'mode', 'interval_seconds', 'times', 'days',
    'active_from', 'active_until', 'tag_id', 'order', 'created_by',
])]
class AutoQueue extends Model
{
    use BelongsToAccount;

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const TIMES = 'times';

    public const INTERVAL = 'interval';

    /** Fastest interval: the scheduler checks every 10 seconds (Telegram allows ~20 posts / minute per chat). */
    public const MIN_INTERVAL_SECONDS = 10;

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
            'interval_seconds' => 'integer',
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

    public function isInterval(): bool
    {
        return $this->mode === self::INTERVAL;
    }

    /**
     * "Every 30 min", "Every 10 sec", "Every 6 hours"…
     */
    public function intervalLabel(): string
    {
        $s = (int) $this->interval_seconds;

        return match (true) {
            $s === 60 => 'Every minute',
            $s === 3600 => 'Every hour',
            $s === 86400 => 'Every day',
            $s % 3600 === 0 => 'Every '.($s / 3600).' hours',
            $s % 60 === 0 => 'Every '.($s / 60).' min',
            default => 'Every '.$s.' sec',
        };
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
