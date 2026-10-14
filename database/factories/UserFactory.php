<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->withRole(Role::SUPER_ADMIN);
    }

    public function admin(): static
    {
        return $this->withRole(Role::ADMIN);
    }

    /**
     * A registered customer on the default (free) plan, or on $planSlug.
     */
    public function customer(?string $planSlug = null): static
    {
        return $this->withRole(Role::CUSTOMER)->state(function () use ($planSlug) {
            if (! Plan::query()->exists()) {
                (new PlansSeeder)->run();
            }

            $plan = $planSlug ? Plan::query()->where('slug', $planSlug)->firstOrFail() : Plan::fallback();

            return [
                'plan_id' => $plan->id,
                'plan_started_at' => now(),
                'plan_expires_at' => $plan->is_default ? null : now()->addMonth(),
            ];
        });
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Disabled]);
    }

    private function withRole(string $slug): static
    {
        return $this->state(function () use ($slug) {
            if (! Role::query()->where('slug', $slug)->exists()) {
                (new RolesAndPermissionsSeeder)->run();
            }

            return ['role_id' => Role::query()->where('slug', $slug)->value('id')];
        });
    }
}
