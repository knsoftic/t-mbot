<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Who may see which account's data.
 *
 * Customers (users without the "accounts.view-all" permission) work only with
 * their own bots, destinations, content and campaigns; staff see everything.
 */
final class Accounts
{
    /**
     * The account the current request is limited to, or null when it may see
     * every account (staff, queue workers, console commands, guests).
     */
    public static function scopedUserId(): ?int
    {
        $user = auth()->user();

        return $user instanceof User && $user->isCustomer() ? (int) $user->id : null;
    }

    /**
     * An "exists" rule that also respects the account boundary (validation
     * rules query the table directly and skip Eloquent scopes).
     */
    public static function exists(string $table, bool $softDeletes = true): Exists
    {
        $rule = Rule::exists($table, 'id');

        if ($softDeletes) {
            $rule->whereNull('deleted_at');
        }

        if (($userId = self::scopedUserId()) !== null) {
            $rule->where('user_id', $userId);
        }

        return $rule;
    }

    /**
     * True when the given owner is a customer account (and therefore limited by a plan).
     */
    public static function isCustomer(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        $user = auth()->id() === $userId ? auth()->user() : User::query()->find($userId);

        return $user instanceof User && $user->isCustomer();
    }
}
