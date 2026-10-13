<div>
    <x-page-header title="Administrators" description="People who can sign in to the admin panel.">
        <x-slot:actions>
            <button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Add user</button>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row dark:border-slate-800">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search name or e-mail…" class="input flex-1">
            <select wire:model.live="roleFilter" class="input sm:w-44">
                <option value="">All roles</option>
                @foreach ($roles as $r) <option value="{{ $r->id }}">{{ $r->name }}</option> @endforeach
            </select>
            <select wire:model.live="statusFilter" class="input sm:w-40">
                <option value="">All statuses</option>
                @foreach (\App\Enums\UserStatus::options() as $v => $l) <option value="{{ $v }}">{{ $l }}</option> @endforeach
            </select>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>User</th><th>Role</th><th>Status</th><th class="hidden md:table-cell">Last login</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr wire:key="u-{{ $user->id }}">
                            <td>
                                <div class="font-medium text-slate-900 dark:text-white">{{ $user->name }} @if ($user->id === auth()->id())<x-badge color="blue">You</x-badge>@endif</div>
                                <div class="text-xs text-slate-500">{{ $user->email }}</div>
                            </td>
                            <td><x-badge :color="$user->isSuperAdmin() ? 'indigo' : 'slate'">{{ $user->role?->name ?? 'No role' }}</x-badge></td>
                            <td><x-status :value="$user->status" /></td>
                            <td class="hidden text-xs text-slate-500 md:table-cell">
                                {{ $user->last_login_at ? display_time($user->last_login_at, 'M j, g:i A') : 'Never' }}
                                @if ($user->last_login_ip) <div class="font-mono text-slate-400">{{ $user->last_login_ip }}</div> @endif
                            </td>
                            <td>
                                <div class="flex justify-end gap-1">
                                    @can('update', $user)
                                        <button type="button" wire:click="edit({{ $user->id }})" class="btn-icon" title="Edit"><x-icon name="pencil" class="size-4" /></button>
                                        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                                            <button type="button" class="btn-icon" x-on:click="open = !open" title="More"><x-icon name="dots" class="size-4" /></button>
                                            <div x-show="open" x-cloak x-transition class="dropdown w-52">
                                                <button type="button" wire:click="openPassword({{ $user->id }})" class="dropdown-item"><x-icon name="key" class="size-4" /> Set new password</button>
                                                <button type="button" wire:click="sendResetLink({{ $user->id }})" class="dropdown-item"><x-icon name="send" class="size-4" /> E-mail reset link</button>
                                                @if ($user->id !== auth()->id())
                                                    <button type="button" wire:click="toggle({{ $user->id }})" class="dropdown-item"><x-icon :name="$user->isActive() ? 'pause' : 'play'" class="size-4" /> {{ $user->isActive() ? 'Disable' : 'Enable' }}</button>
                                                    <x-confirm action="delete({{ $user->id }})" class="dropdown-item text-red-600" title="Delete user?" :message="'Delete '.$user->email.'? Their audit history is kept.'" confirm-text="Delete">
                                                        <x-icon name="trash" class="size-4" /> Delete
                                                    </x-confirm>
                                                @endif
                                            </div>
                                        </div>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-empty icon="users" title="No users found" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="border-t border-slate-200 px-4 py-3 dark:border-slate-800">{{ $users->links() }}</div>
        @endif
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit user' : 'Add user'" max-width="lg">
        <form wire:submit="save" id="user-form" class="space-y-4">
            <x-field label="Name" for="name" required><input id="name" type="text" wire:model="name" class="input"></x-field>
            <x-field label="E-mail" for="email" required><input id="email" type="email" wire:model="email" class="input" autocomplete="off"></x-field>
            <div class="grid grid-cols-2 gap-3">
                <x-field label="Role" for="role_id" required>
                    <select id="role_id" wire:model="role_id" class="input">
                        <option value="">Select…</option>
                        @foreach ($roles as $r) <option value="{{ $r->id }}">{{ $r->name }}</option> @endforeach
                    </select>
                </x-field>
                <x-field label="Status" for="status">
                    <select id="status" wire:model="status" class="input">
                        @foreach (\App\Enums\UserStatus::options() as $v => $l) <option value="{{ $v }}">{{ $l }}</option> @endforeach
                    </select>
                </x-field>
            </div>
            <x-field label="Timezone" for="timezone" hint="Leave empty to use the system timezone.">
                <select id="timezone" wire:model="timezone" class="input">
                    <option value="">System default</option>
                    @foreach ($timezones as $tz) <option value="{{ $tz }}">{{ $tz }}</option> @endforeach
                </select>
            </x-field>
            <x-field :label="$editingId ? 'New password (optional)' : 'Password'" for="password" :required="! $editingId" hint="Stored as a secure hash.">
                <input id="password" type="password" wire:model="password" class="input" autocomplete="new-password">
            </x-field>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-secondary" x-on:click="show = false">Cancel</button>
            <button type="submit" form="user-form" class="btn btn-primary">Save</button>
        </x-slot:footer>
    </x-modal>

    <x-modal wire:model="showPassword" title="Set a new password" max-width="md">
        <form wire:submit="setPassword" id="pw-form">
            <x-field label="New password" for="newPassword" error="newPassword" hint="The user's API tokens are revoked.">
                <input id="newPassword" type="password" wire:model="newPassword" class="input" autocomplete="new-password">
            </x-field>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-secondary" x-on:click="show = false">Cancel</button>
            <button type="submit" form="pw-form" class="btn btn-primary">Set password</button>
        </x-slot:footer>
    </x-modal>
</div>
