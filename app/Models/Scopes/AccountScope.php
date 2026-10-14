<?php

namespace App\Models\Scopes;

use App\Support\Accounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Customers only ever see their own rows. Staff (Admin / Super Admin),
 * queue workers and console commands are not limited.
 */
class AccountScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (($userId = Accounts::scopedUserId()) !== null) {
            $builder->where($model->qualifyColumn('user_id'), $userId);
        }
    }
}
