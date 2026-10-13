<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Users are not seeded: create the first Super Admin with
     * `php artisan app:create-admin`. Demo data is opt-in:
     * `php artisan db:seed --class=DemoSeeder`.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
