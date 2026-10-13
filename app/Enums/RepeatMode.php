<?php

namespace App\Enums;

enum RepeatMode: string
{
    use Concerns;

    case None = 'none';
    case Immediate = 'immediate';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Indefinite = 'indefinite';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Stop (mark completed)',
            self::Immediate => 'Repeat immediately',
            self::Daily => 'Repeat daily',
            self::Weekly => 'Repeat weekly',
            self::Indefinite => 'Repeat indefinitely',
        };
    }

    /**
     * Whether a repeat limit (number of extra cycles) applies.
     */
    public function supportsLimit(): bool
    {
        return in_array($this, [self::Immediate, self::Daily, self::Weekly], true);
    }
}
