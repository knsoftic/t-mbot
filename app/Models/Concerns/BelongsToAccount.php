<?php

namespace App\Models\Concerns;

use App\Models\Scopes\AccountScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row owned by one user account (column user_id).
 *
 * @property int|null $user_id
 */
trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope(new AccountScope);

        // Rows created in the panel or the API belong to the signed-in user,
        // unless the code set an owner explicitly (even null).
        static::creating(function (self $model) {
            if (! array_key_exists('user_id', $model->getAttributes()) && ($id = auth()->id())) {
                $model->user_id = $id;
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOwnedBy(Builder $query, ?int $userId): void
    {
        $userId === null
            ? $query->whereNull($this->qualifyColumn('user_id'))
            : $query->where($this->qualifyColumn('user_id'), $userId);
    }
}
