<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Services\SettingsService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role_id', 'status', 'timezone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Permission slugs resolved once per request.
     *
     * @var array<int, string>|null
     */
    protected ?array $permissionCache = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role?->slug === Role::SUPER_ADMIN;
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->permissionCache === null) {
            $this->permissionCache = $this->role
                ? $this->role->permissions()->pluck('slug')->all()
                : [];
        }

        return in_array($permission, $this->permissionCache, true);
    }

    /**
     * Timezone used when displaying dates to this user.
     */
    public function displayTimezone(): string
    {
        return $this->timezone ?: app(SettingsService::class)->timezone();
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', UserStatus::Active);
    }
}
