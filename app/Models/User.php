<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Services\SettingsService;
use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'whatsapp', 'password', 'role_id', 'status', 'timezone'])]
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
            'plan_started_at' => 'immutable_datetime',
            'plan_expires_at' => 'immutable_datetime',
            'plan_reminder_sent_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * The plan that was bought (may have expired; see currentPlan()).
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<PlanOrder, $this>
     */
    public function planOrders(): HasMany
    {
        return $this->hasMany(PlanOrder::class);
    }

    /**
     * Staff (Super Admin, or a role with "accounts.view-all") see every account
     * and have no plan limits. Everybody else is a customer.
     */
    public function seesAllAccounts(): bool
    {
        return $this->hasPermission(Permissions::ACCOUNTS_VIEW_ALL);
    }

    public function isCustomer(): bool
    {
        return ! $this->seesAllAccounts();
    }

    /**
     * The plan whose limits apply right now: the bought plan while it is valid,
     * otherwise the free plan. Staff have no plan (no limits).
     */
    public function currentPlan(): ?Plan
    {
        if (! $this->isCustomer()) {
            return null;
        }

        if ($this->plan && ! $this->planExpired()) {
            return $this->plan;
        }

        return Plan::fallback();
    }

    public function planExpired(): bool
    {
        return $this->plan_expires_at !== null && $this->plan_expires_at->isPast();
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
