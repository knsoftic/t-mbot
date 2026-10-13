<?php

namespace App\Enums;

/**
 * Shared helpers for backed enums that are shown in the admin panel.
 */
trait Concerns
{
    /**
     * @return array<string, string> value => label, for select inputs.
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    /**
     * Badge colour name understood by the <x-badge> component.
     */
    public function color(): string
    {
        return 'slate';
    }
}
