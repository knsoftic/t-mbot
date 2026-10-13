<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

/**
 * Idempotent: safe to run on every deploy to pick up new permissions.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Permissions::definitions() as $slug => $definition) {
            Permission::query()->updateOrCreate(['slug' => $slug], $definition);
        }

        Role::query()->updateOrCreate(
            ['slug' => Role::SUPER_ADMIN],
            ['name' => 'Super Admin', 'description' => 'Full access to everything, including users, roles and settings.', 'is_system' => true],
        );

        $admin = Role::query()->firstOrCreate(
            ['slug' => Role::ADMIN],
            ['name' => 'Admin', 'description' => 'Manages bots, destinations, content, campaigns and logs.', 'is_system' => true],
        );

        // Only seed the Admin defaults the first time, so edits made in the UI are kept.
        if ($admin->wasRecentlyCreated) {
            $admin->permissions()->sync(Permission::query()->whereIn('slug', Permissions::adminDefaults())->pluck('id'));
        }
    }
}
