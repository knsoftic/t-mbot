<?php

namespace App\Enums;

enum DestinationType: string
{
    use Concerns;

    case Channel = 'channel';
    case Group = 'group';
    case Supergroup = 'supergroup';

    public function color(): string
    {
        return match ($this) {
            self::Channel => 'indigo',
            self::Group => 'teal',
            self::Supergroup => 'cyan',
        };
    }

    /**
     * @return list<self> the types shown under the sidebar "Groups" entry.
     */
    public static function groupTypes(): array
    {
        return [self::Group, self::Supergroup];
    }
}
