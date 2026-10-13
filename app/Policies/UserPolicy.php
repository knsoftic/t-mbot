<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;

/**
 * Admin management rules. Super Admins are allowed everything by Gate::before,
 * except the self-protection rules below which are checked explicitly.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permissions::USERS_MANAGE);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permissions::USERS_MANAGE);
    }

    public function update(User $actor, User $target): bool
    {
        // Only Super Admins may edit Super Admins.
        return $actor->hasPermission(Permissions::USERS_MANAGE) && (! $target->isSuperAdmin() || $actor->isSuperAdmin());
    }

    public function delete(User $actor, User $target): bool
    {
        return $this->update($actor, $target) && $actor->id !== $target->id;
    }

    /**
     * Cannot disable yourself or the last active Super Admin.
     */
    public static function canChangeStatusOrRole(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return false;
        }

        if ($target->isSuperAdmin()) {
            return User::query()->active()->whereHas('role', fn ($q) => $q->where('slug', Role::SUPER_ADMIN))->count() > 1;
        }

        return true;
    }
}
