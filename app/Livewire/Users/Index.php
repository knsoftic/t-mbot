<?php

namespace App\Livewire\Users;

use App\Enums\UserStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Campaign;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Scopes\AccountScope;
use App\Models\TelegramBot;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\AuditLogger;
use App\Services\BillingService;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Users')]
class Index extends Component
{
    use InteractsWithUi, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $roleFilter = '';

    #[Url(except: '')]
    public string $statusFilter = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $role_id = '';

    public string $status = 'active';

    public string $timezone = '';

    public string $password = '';

    public bool $showPassword = false;

    public ?int $passwordUserId = null;

    public string $newPassword = '';

    public bool $showPlan = false;

    public ?int $planUserId = null;

    public string $planId = '';

    /** Last day of the plan (Y-m-d, in the admin's timezone). */
    public string $planExpires = '';

    public function mount(): void
    {
        $this->authorize(Permissions::USERS_MANAGE);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'roleFilter', 'statusFilter'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->resetForm();
        $this->role_id = (string) Role::query()->where('slug', Role::ADMIN)->value('id');
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $user = User::query()->findOrFail($id);
        $this->authorize('update', $user);

        $this->resetForm();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role_id = (string) $user->role_id;
        $this->status = $user->status->value;
        $this->timezone = (string) $user->timezone;
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $user = $this->editingId ? User::query()->findOrFail($this->editingId) : null;
        $user ? $this->authorize('update', $user) : $this->authorize('create', User::class);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'role_id' => ['required', Rule::exists('roles', 'id')],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'timezone' => ['nullable', 'timezone:all'],
            'password' => [$user ? 'nullable' : 'required', PasswordRule::defaults()],
        ]);

        $role = Role::query()->findOrFail($data['role_id']);
        $actor = auth()->user();

        if ($role->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            $this->addError('role_id', 'Only a Super Admin can grant the Super Admin role.');

            return;
        }

        if ($user && (($user->role_id !== (int) $data['role_id']) || $user->status->value !== $data['status']) && ! UserPolicy::canChangeStatusOrRole($actor, $user)) {
            $this->addError('status', $user->id === $actor->id ? 'You cannot change your own role or status.' : 'At least one active Super Admin must remain.');

            return;
        }

        $user ??= new User;
        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'role_id' => $data['role_id'],
            'status' => $data['status'],
            'timezone' => $data['timezone'] ?: null,
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password'];
            $user->password_changed_at = now();
        }

        $isNew = ! $user->exists;
        $user->save();

        if (! $user->isActive()) {
            $user->tokens()->delete();
        }

        $audit->log($isNew ? 'user.created' : 'user.updated', $user, ($isNew ? 'User created: ' : 'User edited: ').$user->email, $isNew ? ['role' => $role->name] : $audit->changes($user));

        $this->showForm = false;
        $this->toast("User {$user->email} saved.");
    }

    public function toggle(int $id, AuditLogger $audit): void
    {
        $user = User::query()->findOrFail($id);
        $this->authorize('update', $user);

        if (! UserPolicy::canChangeStatusOrRole(auth()->user(), $user)) {
            $this->toast($user->id === auth()->id() ? 'You cannot disable your own account.' : 'At least one active Super Admin must remain.', 'error');

            return;
        }

        $user->status = $user->isActive() ? UserStatus::Disabled : UserStatus::Active;
        $user->save();

        if (! $user->isActive()) {
            // Sign the user out everywhere.
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        $audit->log($user->isActive() ? 'user.enabled' : 'user.disabled', $user, "User {$user->email} ".($user->isActive() ? 'enabled' : 'disabled'));
        $this->toast("{$user->email} ".($user->isActive() ? 'enabled' : 'disabled').'.');
    }

    public function sendResetLink(int $id, AuditLogger $audit): void
    {
        $user = User::query()->findOrFail($id);
        $this->authorize('update', $user);

        $status = Password::sendResetLink(['email' => $user->email]);
        $audit->log('user.password_reset_link', $user, "Password reset link sent to {$user->email}");
        $this->toast($status === Password::RESET_LINK_SENT ? "Reset link e-mailed to {$user->email}." : __($status), $status === Password::RESET_LINK_SENT ? 'success' : 'warning');
    }

    public function openPassword(int $id): void
    {
        $user = User::query()->findOrFail($id);
        $this->authorize('update', $user);
        $this->passwordUserId = $id;
        $this->newPassword = '';
        $this->resetValidation();
        $this->showPassword = true;
    }

    public function setPassword(AuditLogger $audit): void
    {
        $user = User::query()->findOrFail($this->passwordUserId);
        $this->authorize('update', $user);
        $this->validate(['newPassword' => ['required', PasswordRule::defaults()]], [], ['newPassword' => 'password']);

        $user->forceFill(['password' => $this->newPassword, 'password_changed_at' => now(), 'remember_token' => null])->save();
        $user->tokens()->delete();
        $audit->log('user.password_set', $user, "Password set by administrator for {$user->email}");

        $this->showPassword = false;
        $this->newPassword = '';
        $this->toast("New password set for {$user->email}. Share it securely.");
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $user = User::query()->findOrFail($id);
        $this->authorize('delete', $user);

        if ($user->id === auth()->id() || ! UserPolicy::canChangeStatusOrRole(auth()->user(), $user)) {
            $this->toast($user->id === auth()->id() ? 'You cannot delete your own account.' : 'At least one active Super Admin must remain.', 'error');

            return;
        }

        // Deleting the owner would leave bots and campaigns running without an account.
        $ownsData = TelegramBot::withTrashed()->withoutGlobalScope(AccountScope::class)->where('user_id', $user->id)->exists()
            || Campaign::withTrashed()->withoutGlobalScope(AccountScope::class)->where('user_id', $user->id)->exists();

        if ($ownsData) {
            $this->toast("{$user->email} owns bots or campaigns. Disable the account instead (its campaigns stop being editable but nothing is lost).", 'error');

            return;
        }

        $user->tokens()->delete();
        $user->delete();
        $audit->log('user.deleted', $user, "User {$user->email} deleted");
        $this->toast("{$user->email} deleted.");
    }

    public function openPlan(int $id): void
    {
        $this->authorize(Permissions::ORDERS_MANAGE);
        $user = User::query()->findOrFail($id);
        $this->planUserId = $user->id;
        $this->planId = (string) ($user->plan_id ?? Plan::fallback()?->id);
        $this->planExpires = $user->plan_expires_at
            ? $user->plan_expires_at->setTimezone(display_tz())->format('Y-m-d')
            : now(display_tz())->addMonthNoOverflow()->format('Y-m-d');
        $this->resetValidation();
        $this->showPlan = true;
    }

    /**
     * Set a customer's plan by hand (gift, correction, payment received outside the panel).
     */
    public function savePlan(BillingService $billing): void
    {
        $this->authorize(Permissions::ORDERS_MANAGE);
        $user = User::query()->findOrFail($this->planUserId);
        $plan = Plan::query()->find((int) $this->planId);

        $this->validate([
            'planId' => ['required', Rule::exists('plans', 'id')],
            'planExpires' => [$plan?->is_default ? 'nullable' : 'required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ], [], ['planId' => 'plan', 'planExpires' => 'valid until']);

        $expires = $plan->is_default ? null : CarbonImmutable::parse($this->planExpires, display_tz())->endOfDay()->utc();
        $billing->assignPlan($user, $plan, $expires, auth()->user());

        $this->showPlan = false;
        $this->toast("{$user->email} is now on {$plan->name}".($expires ? ' until '.display_time($expires, 'd M Y') : '').'.');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'email', 'role_id', 'timezone', 'password']);
        $this->status = UserStatus::Active->value;
        $this->resetValidation();
    }

    public function render()
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';

        return view('livewire.users.index', [
            'users' => User::query()->with(['role', 'plan'])
                ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)))
                ->when($this->roleFilter !== '', fn ($q) => $q->where('role_id', $this->roleFilter))
                ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
                ->orderBy('name')
                ->paginate(20),
            'roles' => Role::query()->orderBy('name')->get(),
            'plans' => Plan::query()->orderBy('sort_order')->orderBy('price_usd')->get(['id', 'name', 'price_usd', 'is_default']),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }
}
