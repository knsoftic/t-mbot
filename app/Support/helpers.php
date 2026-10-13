<?php

use App\Models\User;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;

if (! function_exists('display_tz')) {
    /**
     * Timezone for displaying dates to the current user.
     */
    function display_tz(): string
    {
        $user = auth()->user();

        return $user instanceof User ? $user->displayTimezone() : app(SettingsService::class)->timezone();
    }
}

if (! function_exists('display_time')) {
    /**
     * Format a UTC timestamp in the given (or the user's) timezone.
     */
    function display_time(?DateTimeInterface $value, string $format = 'M j, Y g:i A', ?string $timezone = null): string
    {
        if ($value === null) {
            return '—';
        }

        return CarbonImmutable::instance($value)->setTimezone($timezone ?: display_tz())->format($format);
    }
}

if (! function_exists('relative_time')) {
    function relative_time(?DateTimeInterface $value): string
    {
        return $value ? CarbonImmutable::instance($value)->diffForHumans() : '—';
    }
}
