<div>
    <x-page-header title="Roles" description="Roles bundle permissions. Super Admin always has full access; create additional roles as needed.">
        <x-slot:actions>
            <button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" class="size-4" /> New role</button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($roles as $role)
            <div wire:key="role-{{ $role->id }}" class="card flex flex-col p-5">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="font-semibold text-slate-900 dark:text-white">{{ $role->name }}</div>
                        <div class="mt-0.5 text-xs text-slate-500">{{ $role->description }}</div>
                    </div>
                    @if ($role->is_system) <x-badge color="indigo">Built-in</x-badge> @endif
                </div>
                <div class="mt-4 flex gap-4 text-sm text-slate-600 dark:text-slate-300">
                    <span><strong>{{ $role->users_count }}</strong> users</span>
                    <span><strong>{{ $role->isSuperAdmin() ? 'All' : $role->permissions_count }}</strong> permissions</span>
                </div>
                <div class="mt-auto flex justify-end gap-1 pt-4">
                    @unless ($role->isSuperAdmin())
                        <button type="button" wire:click="edit({{ $role->id }})" class="btn btn-secondary btn-sm"><x-icon name="pencil" class="size-4" /> Permissions</button>
                    @endunless
                    @unless ($role->is_system)
                        <x-confirm action="delete({{ $role->id }})" class="btn-icon hover:text-red-600" title="Delete role?" confirm-text="Delete"><x-icon name="trash" class="size-4" /></x-confirm>
                    @endunless
                </div>
            </div>
        @endforeach
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit role' : 'New role'" max-width="3xl">
        <form wire:submit="save" id="role-form" class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-field label="Name" for="name" required><input id="name" type="text" wire:model="name" class="input"></x-field>
                <x-field label="Description" for="description"><input id="description" type="text" wire:model="description" class="input"></x-field>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($groups as $group => $items)
                    <fieldset class="rounded-lg p-3 ring-1 ring-slate-200 dark:ring-slate-700">
                        <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $group }}</legend>
                        @foreach ($items as $slug => $def)
                            <label class="flex items-start gap-2 py-1 text-sm">
                                <input type="checkbox" wire:model="permissions" value="{{ $slug }}" class="checkbox mt-0.5">
                                <span><span class="font-medium">{{ $def['name'] }}</span><span class="block text-xs text-slate-500">{{ $def['description'] }}</span></span>
                            </label>
                        @endforeach
                    </fieldset>
                @endforeach
            </div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-secondary" x-on:click="show = false">Cancel</button>
            <button type="submit" form="role-form" class="btn btn-primary">Save role</button>
        </x-slot:footer>
    </x-modal>
</div>
