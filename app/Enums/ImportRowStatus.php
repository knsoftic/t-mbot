<?php

namespace App\Enums;

/**
 * What happened to one person of a member import.
 */
enum ImportRowStatus: string
{
    use Concerns;

    case Pending = 'pending';
    case Added = 'added';
    case Invited = 'invited';
    case Waiting = 'waiting';
    case AlreadyMember = 'already_member';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Added => 'Added',
            self::Invited => 'Invited',
            self::Waiting => 'Waiting for request',
            self::AlreadyMember => 'Already member',
            self::Skipped => 'Skipped',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'slate',
            self::Added => 'green',
            self::Invited => 'blue',
            self::Waiting => 'amber',
            self::AlreadyMember => 'teal',
            self::Skipped => 'zinc',
            self::Failed => 'red',
        };
    }

    /**
     * Listed people whose join request is approved automatically.
     *
     * @return list<self>
     */
    public static function allowlisted(): array
    {
        return [self::Invited, self::Waiting];
    }
}
