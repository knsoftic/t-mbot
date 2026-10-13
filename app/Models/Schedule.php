<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A time-of-day slot (optionally limited to weekdays) used by the
 * fixed-time, daily and weekly campaign triggers.
 */
#[Fillable(['campaign_id', 'schedule_type', 'time_of_day', 'days', 'timezone', 'status'])]
class Schedule extends Model
{
    public const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    protected function casts(): array
    {
        return ['days' => 'array'];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @return list<int> ISO weekdays this slot applies to.
     */
    public function weekdays(): array
    {
        $days = array_map('intval', $this->days ?? []);

        return $days === [] ? array_keys(self::WEEKDAYS) : array_values(array_unique($days));
    }

    public function summary(): string
    {
        $time = Carbon::createFromFormat('H:i:s', strlen($this->time_of_day) === 5 ? $this->time_of_day.':00' : $this->time_of_day)->format('h:i A');
        $days = $this->days ? implode(', ', array_map(fn ($d) => self::WEEKDAYS[(int) $d] ?? $d, $this->weekdays())) : 'Every day';

        return "{$days} at {$time}";
    }
}
