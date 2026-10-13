<?php

namespace App\Enums;

enum TriggerType: string
{
    use Concerns;

    case Manual = 'manual';
    case Scheduled = 'scheduled';
    case Interval = 'interval';
    case FixedTime = 'fixed_time';
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual (Run Now only)',
            self::Scheduled => 'Scheduled (exact date & time per post)',
            self::Interval => 'Interval',
            self::FixedTime => 'Fixed times of day',
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::FixedTime => 'Fixed times',
            default => ucfirst($this->value),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Manual => 'Nothing is posted automatically. Each click on Run Now posts the next item.',
            self::Scheduled => 'Every item gets its own exact publish date and time.',
            self::Interval => 'Post one item every N minutes, starting at the start time.',
            self::FixedTime => 'Post one item at each of several times every day.',
            self::Daily => 'Post one item every day at a set time.',
            self::Weekly => 'Post one item on selected weekdays at a set time.',
        };
    }

    /**
     * Triggers whose slots come from rows in the schedules table.
     */
    public function usesTimeSlots(): bool
    {
        return in_array($this, [self::FixedTime, self::Daily, self::Weekly], true);
    }
}
