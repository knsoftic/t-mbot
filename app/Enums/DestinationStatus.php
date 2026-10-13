<?php

namespace App\Enums;

enum DestinationStatus: string
{
    use Concerns;

    case Active = 'active';
    case Inactive = 'inactive';

    public function color(): string
    {
        return $this === self::Active ? 'green' : 'slate';
    }
}
