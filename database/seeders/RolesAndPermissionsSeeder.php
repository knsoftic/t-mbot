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
        $new = [];

        foreach (Permissions::definitions() as $slug => $definition) {
            $permission = Permission::query()->updateOrCreate(['slug' => $slug], $definition);

            if ($permission->wasRecentlyCreated) {
                $new[] = $slug;
            }
        }

        Role::query()->updateOrCreate(
            ['slug' => Role::SUPER_ADMIN],
            ['name' => 'Super Admin', 'description' => 'Full access to everything, including users, roles and settings.', 'is_system' => true],
        );

        $admin = Role::query()->firstOrCreate(
            ['slug' => Role::ADMIN],
            ['name' => 'Admin', 'description' => 'Manages every account\'s bots, destinations, content, campaigns and logs, and handles purchase requests.', 'is_system' => true],
        );

        $customer = Role::query()->firstOrCreate(
            ['slug' => Role::CUSTOMER],
            ['name' => 'Customer', 'description' => 'Registered user: manages only their own bots, channels, content and campaigns within their plan.', 'is_system' => true],
        );

        // Defaults are applied once, so edits made in the UI are kept; permissions
        // added by a later release are still given to the built-in roles.
        $this->grant($admin, Permissions::adminDefaults(), $new);
        $this->grant($customer, Permissions::customerDefaults(), $new);
    }

    /**
     * @param  list<string>  $defaults
     * @param  list<string>  $newPermissions
     */
    private function grant(Role $role, array $defaults, array $newPermissions): void
    {
        $slugs = $role->wasRecentlyCreated ? $defaults : array_values(array_intersect($defaults, $newPermissions));

        if ($slugs !== []) {
            $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('slug', $slugs)->pluck('id'));
        }
    }
}
