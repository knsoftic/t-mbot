<?php

namespace App\Enums;

enum OrderStatus: string
{
    use Concerns;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'green',
            self::Rejected => 'red',
            self::Cancelled => 'slate',
        };
    }
}
