<?php

namespace App\Support;

/**
 * Central catalogue of permission slugs.
 *
 * Abilities are checked with Gate / @can using these slugs. Super Admins
 * bypass all checks (see AuthServiceProvider logic in AppServiceProvider).
 * Adding a new permission only requires adding it here and re-running
 * `php artisan db:seed --class=RolesAndPermissionsSeeder`.
 */
final class Permissions
{
    public const DASHBOARD_VIEW = 'dashboard.view';

    public const BOTS_VIEW = 'bots.view';

    public const BOTS_MANAGE = 'bots.manage';

    public const DESTINATIONS_VIEW = 'destinations.view';

    public const DESTINATIONS_MANAGE = 'destinations.manage';

    public const CONTENTS_VIEW = 'contents.view';

    public const CONTENTS_MANAGE = 'contents.manage';

    public const CAMPAIGNS_VIEW = 'campaigns.view';

    public const CAMPAIGNS_MANAGE = 'campaigns.manage';

    public const CAMPAIGNS_OPERATE = 'campaigns.operate';

    public const LOGS_VIEW = 'logs.view';

    public const FAILED_JOBS_MANAGE = 'failed-jobs.manage';

    public const AUDIT_VIEW = 'audit.view';

    public const USERS_MANAGE = 'users.manage';

    public const ROLES_MANAGE = 'roles.manage';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const SYSTEM_HEALTH = 'system.health';

    /**
     * @return array<string, array{name: string, group: string, description: string}>
     */
    public static function definitions(): array
    {
        return [
            self::DASHBOARD_VIEW => ['name' => 'View dashboard', 'group' => 'General', 'description' => 'See statistics and recent activity.'],
            self::BOTS_VIEW => ['name' => 'View bots', 'group' => 'Telegram', 'description' => 'List bots and their status.'],
            self::BOTS_MANAGE => ['name' => 'Manage bots', 'group' => 'Telegram', 'description' => 'Add, edit, test, enable/disable and delete bots.'],
            self::DESTINATIONS_VIEW => ['name' => 'View channels & groups', 'group' => 'Telegram', 'description' => 'List destinations.'],
            self::DESTINATIONS_MANAGE => ['name' => 'Manage channels & groups', 'group' => 'Telegram', 'description' => 'Add, edit, test and delete destinations.'],
            self::CONTENTS_VIEW => ['name' => 'View content', 'group' => 'Content', 'description' => 'Browse the content library.'],
            self::CONTENTS_MANAGE => ['name' => 'Manage content', 'group' => 'Content', 'description' => 'Create, edit, duplicate and delete content and tags.'],
            self::CAMPAIGNS_VIEW => ['name' => 'View campaigns', 'group' => 'Automation', 'description' => 'See campaigns, schedules and progress.'],
            self::CAMPAIGNS_MANAGE => ['name' => 'Manage campaigns', 'group' => 'Automation', 'description' => 'Create, edit and delete campaigns and sequences.'],
            self::CAMPAIGNS_OPERATE => ['name' => 'Operate campaigns', 'group' => 'Automation', 'description' => 'Activate, run now, pause, resume, stop, restart, skip and retry.'],
            self::LOGS_VIEW => ['name' => 'View posting logs', 'group' => 'Monitoring', 'description' => 'See every posting attempt.'],
            self::FAILED_JOBS_MANAGE => ['name' => 'Manage failed jobs', 'group' => 'Monitoring', 'description' => 'Inspect, retry and delete failed queue jobs.'],
            self::AUDIT_VIEW => ['name' => 'View audit log', 'group' => 'Monitoring', 'description' => 'See who changed what.'],
            self::USERS_MANAGE => ['name' => 'Manage administrators', 'group' => 'Users', 'description' => 'Create, edit, disable and delete users.'],
            self::ROLES_MANAGE => ['name' => 'Manage roles', 'group' => 'Users', 'description' => 'Create roles and assign permissions.'],
            self::SETTINGS_MANAGE => ['name' => 'Manage settings', 'group' => 'System', 'description' => 'Change system settings.'],
            self::SYSTEM_HEALTH => ['name' => 'View system health', 'group' => 'System', 'description' => 'See database, queue, scheduler and Telegram health.'],
        ];
    }

    /**
     * Permissions granted to the built-in "Admin" role.
     *
     * @return list<string>
     */
    public static function adminDefaults(): array
    {
        return [
            self::DASHBOARD_VIEW,
            self::BOTS_VIEW, self::BOTS_MANAGE,
            self::DESTINATIONS_VIEW, self::DESTINATIONS_MANAGE,
            self::CONTENTS_VIEW, self::CONTENTS_MANAGE,
            self::CAMPAIGNS_VIEW, self::CAMPAIGNS_MANAGE, self::CAMPAIGNS_OPERATE,
            self::LOGS_VIEW, self::FAILED_JOBS_MANAGE,
            self::SYSTEM_HEALTH,
        ];
    }
}
