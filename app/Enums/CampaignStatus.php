<?php

namespace App\Enums;

enum CampaignStatus: string
{
    use Concerns;

    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return $this === self::Cancelled ? 'Stopped' : ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Scheduled => 'blue',
            self::Running => 'green',
            self::Paused => 'amber',
            self::Completed => 'indigo',
            self::Failed => 'red',
            self::Cancelled => 'zinc',
        };
    }

    /**
     * Statuses in which the scheduler is allowed to post.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Scheduled, self::Running];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    /**
     * Statuses from which "Run Now" may post an item.
     */
    public function allowsManualRun(): bool
    {
        return in_array($this, [self::Scheduled, self::Running, self::Paused], true);
    }
}
