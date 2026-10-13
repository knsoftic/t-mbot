<div>
    <x-page-header title="Categories / Tags" description="Organise the content library. Tags can be used to search and filter content.">
        <x-slot:breadcrumb>
            <a href="{{ route('contents.index') }}" wire:navigate class="hover:text-brand-600">Content Library</a>
            <x-icon name="chevron-right" class="size-3" /> <span>Tags</span>
        </x-slot:breadcrumb>
    </x-page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        @can('contents.manage')
            <form wire:submit="save" class="card h-fit space-y-4 p-5">
                <h2 class="card-title">{{ $editingId ? 'Edit tag' : 'New tag' }}</h2>
                <x-field label="Name" for="name" required>
                    <input id="name" type="text" wire:model="name" class="input" maxlength="50">
                </x-field>
                <div>
                    <span class="label">Color</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach (\App\Models\Tag::COLORS as $c)
                            <label wire:key="c-{{ $c }}" class="cursor-pointer">
                                <input type="radio" wire:model.live="color" value="{{ $c }}" class="peer sr-only">
                                <x-badge :color="$c" class="peer-checked:ring-2 peer-checked:ring-offset-1">{{ ucfirst($c) }}</x-badge>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    @if ($editingId)
                        <button type="button" wire:click="cancel" class="btn btn-secondary">Cancel</button>
                    @endif
                    <button type="submit" class="btn btn-primary">{{ $editingId ? 'Update' : 'Add tag' }}</button>
                </div>
            </form>
        @endcan

        <div class="card lg:col-span-2">
            <div class="border-b border-slate-200 p-4 dark:border-slate-800">
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search tags…" class="input">
            </div>
            @if ($tags->isEmpty())
                <x-empty icon="tag" title="No tags yet" description="Tags are created here or automatically when you type them on a content item." />
            @else
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($tags as $tag)
                        <li wire:key="tag-{{ $tag->id }}" class="flex items-center gap-3 px-4 py-3">
                            <x-badge :color="$tag->color">{{ $tag->name }}</x-badge>
                            <a href="{{ route('contents.index', ['tag' => $tag->id]) }}" wire:navigate class="text-xs text-slate-500 hover:text-brand-600">{{ $tag->contents_count }} {{ str('item')->plural($tag->contents_count) }}</a>
                            @can('contents.manage')
                                <div class="ml-auto flex gap-1">
                                    <button type="button" wire:click="edit({{ $tag->id }})" class="btn-icon" title="Edit"><x-icon name="pencil" class="size-4" /></button>
                                    <x-confirm action="delete({{ $tag->id }})" class="btn-icon hover:text-red-600" title="Delete tag?" :message="'Remove the tag “'.$tag->name.'” from '.$tag->contents_count.' item(s)?'" confirm-text="Delete">
                                        <x-icon name="trash" class="size-4" />
                                    </x-confirm>
                                </div>
                            @endcan
                        </li>
                    @endforeach
                </ul>
                @if ($tags->hasPages())
                    <div class="border-t border-slate-200 px-4 py-3 dark:border-slate-800">{{ $tags->links() }}</div>
                @endif
            @endif
        </div>
    </div>
</div>
