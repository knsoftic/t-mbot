<?php

namespace App\Livewire\Roles;

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Roles')]
class Index extends Component
{
    use InteractsWithUi;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    /** @var list<string> */
    public array $permissions = [];

    public function mount(): void
    {
        $this->authorize(Permissions::ROLES_MANAGE);
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'description', 'permissions']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $role = Role::query()->with('permissions')->findOrFail($id);

        if ($role->isSuperAdmin()) {
            $this->toast('The Super Admin role always has every permission.', 'info');

            return;
        }

        $this->resetValidation();
        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->description = (string) $role->description;
        $this->permissions = $role->permissions->pluck('slug')->all();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize(Permissions::ROLES_MANAGE);
        $role = $this->editingId ? Role::query()->findOrFail($this->editingId) : new Role;

        $this->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->ignore($role->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(array_keys(Permissions::definitions()))],
        ]);

        if ($role->isSuperAdmin()) {
            return;
        }

        $role->name = $this->name;
        $role->description = $this->description ?: null;

        if (! $role->exists) {
            $role->slug = Str::slug($this->name).'-'.Str::lower(Str::random(4));
            $role->is_system = false;
        }

        $role->save();
        $role->permissions()->sync(Permission::query()->whereIn('slug', $this->permissions)->pluck('id'));
        $audit->log($this->editingId ? 'role.updated' : 'role.created', $role, "Role “{$role->name}” saved", ['permissions' => $this->permissions]);

        $this->showForm = false;
        $this->toast("Role “{$role->name}” saved.");
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $this->authorize(Permissions::ROLES_MANAGE);
        $role = Role::query()->withCount('users')->findOrFail($id);

        if ($role->is_system || $role->users_count > 0) {
            $this->toast($role->is_system ? 'Built-in roles cannot be deleted.' : 'Move the users to another role before deleting this one.', 'error');

            return;
        }

        $role->delete();
        $audit->log('role.deleted', $role, "Role “{$role->name}” deleted");
        $this->toast("Role “{$role->name}” deleted.");
    }

    public function render()
    {
        return view('livewire.roles.index', [
            'roles' => Role::query()->withCount(['users', 'permissions'])->orderByDesc('is_system')->orderBy('name')->get(),
            'groups' => collect(Permissions::definitions())->groupBy('group', preserveKeys: true),
        ]);
    }
}
