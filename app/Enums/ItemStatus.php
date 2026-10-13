<?php

namespace App\Enums;

enum ItemStatus: string
{
    use Concerns;

    case Pending = 'pending';
    case Processing = 'processing';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'slate',
            self::Processing => 'blue',
            self::Sent => 'green',
            self::Failed => 'red',
            self::Skipped => 'amber',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Sent, self::Failed, self::Skipped], true);
    }
}
