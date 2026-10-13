<div>
    <x-page-header title="Content Library" description="Text, images, videos, documents, audio and GIFs that campaigns publish.">
        <x-slot:actions>
            <a href="{{ route('tags.index') }}" wire:navigate class="btn btn-secondary"><x-icon name="tag" class="size-4" /> Tags</a>
            @can('contents.manage')
                <a href="{{ route('contents.create') }}" wire:navigate class="btn btn-primary"><x-icon name="plus" class="size-4" /> New content</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 md:grid-cols-12 dark:border-slate-800">
            <div class="relative md:col-span-5">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search title, text, caption or tag…" class="input pl-9">
            </div>
            <select wire:model.live="type" class="input md:col-span-2">
                <option value="">All types</option>
                @foreach (\App\Enums\ContentType::options() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="status" class="input md:col-span-2">
                <option value="">All statuses</option>
                @foreach (\App\Enums\ContentStatus::options() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="tag" class="input md:col-span-2">
                <option value="">All tags</option>
                @foreach ($tags as $t)
                    <option value="{{ $t->id }}">{{ $t->name }}</option>
                @endforeach
            </select>
            <button type="button" wire:click="clearFilters" class="btn btn-ghost md:col-span-1" @disabled(! $filtered)>Clear</button>
        </div>

        <div class="relative">
            <div wire:loading.flex wire:target="search,type,status,tag,gotoPage,nextPage,previousPage,clearFilters" class="absolute inset-0 z-10 items-center justify-center bg-white/60 dark:bg-slate-900/60">
                <x-spinner class="size-6 text-brand-600" />
            </div>

            @if ($contents->isEmpty())
                <x-empty icon="photo" :title="$filtered ? 'No content matches your filters' : 'Your library is empty'"
                         :description="$filtered ? 'Try different filters.' : 'Create text posts or add media by URL, upload or Telegram file ID.'">
                    @if (! $filtered)
                        @can('contents.manage')
                            <a href="{{ route('contents.create') }}" wire:navigate class="btn btn-primary"><x-icon name="plus" class="size-4" /> Create content</a>
                        @endcan
                    @endif
                </x-empty>
            @else
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Content</th>
                                <th class="hidden md:table-cell">Source</th>
                                <th class="hidden lg:table-cell">Tags</th>
                                <th>Status</th>
                                <th class="hidden xl:table-cell">Updated</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($contents as $content)
                                <tr wire:key="content-{{ $content->id }}">
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <x-content-icon :type="$content->type" />
                                            <div class="min-w-0">
                                                <button type="button" wire:click="preview({{ $content->id }})" class="block max-w-xs truncate text-left font-medium text-slate-900 hover:text-brand-600 dark:text-white">{{ $content->title }}</button>
                                                <div class="max-w-xs truncate text-xs text-slate-500">{{ $content->type->label() }} · {{ str($content->body())->limit(70) ?: 'No caption' }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="hidden max-w-[14rem] md:table-cell">
                                        <div class="text-xs text-slate-500">{{ $content->source->label() }}</div>
                                        @if ($content->source !== \App\Enums\ContentSource::None)
                                            <div class="truncate font-mono text-[11px] text-slate-400" title="{{ $content->mediaLabel() }}">{{ $content->mediaLabel() }}</div>
                                        @endif
                                    </td>
                                    <td class="hidden lg:table-cell">
                                        <div class="flex max-w-[14rem] flex-wrap gap-1">
                                            @forelse ($content->tags as $t)
                                                <x-badge :color="$t->color">{{ $t->name }}</x-badge>
                                            @empty
                                                <span class="text-xs text-slate-400">—</span>
                                            @endforelse
                                        </div>
                                    </td>
                                    <td>
                                        <x-status :value="$content->status" />
                                        @if ($content->campaign_items_count)
                                            <div class="mt-1 text-[11px] text-slate-400">In {{ $content->campaign_items_count }} sequence {{ str('slot')->plural($content->campaign_items_count) }}</div>
                                        @endif
                                    </td>
                                    <td class="hidden text-xs text-slate-500 xl:table-cell" title="{{ display_time($content->updated_at) }}">{{ relative_time($content->updated_at) }}</td>
                                    <td>
                                        <div class="flex items-center justify-end gap-1">
                                            <button type="button" wire:click="preview({{ $content->id }})" class="btn-icon" title="Preview"><x-icon name="eye" class="size-4" /></button>
                                            @can('contents.manage')
                                                <a href="{{ route('contents.edit', $content) }}" wire:navigate class="btn-icon" title="Edit"><x-icon name="pencil" class="size-4" /></a>
                                                <button type="button" wire:click="duplicate({{ $content->id }})" class="btn-icon" title="Duplicate"><x-icon name="copy" class="size-4" /></button>
                                                <button type="button" wire:click="toggle({{ $content->id }})" class="btn-icon" title="{{ $content->isActive() ? 'Deactivate' : 'Activate' }}">
                                                    <x-icon :name="$content->isActive() ? 'pause' : 'play'" class="size-4" />
                                                </button>
                                                <x-confirm action="delete({{ $content->id }})" class="btn-icon hover:text-red-600" title="Delete content?"
                                                           :message="'Delete “'.$content->title.'”? Posting history keeps a reference to it.'" confirm-text="Delete">
                                                    <x-icon name="trash" class="size-4" />
                                                </x-confirm>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($contents->hasPages())
                    <div class="border-t border-slate-200 px-4 py-3 dark:border-slate-800">{{ $contents->links() }}</div>
                @endif
            @endif
        </div>
    </div>

    <x-modal wire:model="showPreview" :title="$previewContent ? 'Preview: '.$previewContent->title : 'Preview'" max-width="lg">
        @if ($previewContent)
            <x-tg-preview :content="$previewContent" />
            <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                <dt class="text-slate-500">Type</dt><dd>{{ $previewContent->type->label() }}</dd>
                <dt class="text-slate-500">Source</dt><dd>{{ $previewContent->source->label() }}</dd>
                <dt class="text-slate-500">Parse mode</dt><dd>{{ $previewContent->parse_mode?->value ?? 'Plain text' }}</dd>
                <dt class="text-slate-500">Cached Telegram file IDs</dt><dd>{{ $previewContent->botFiles()->count() }} bot(s)</dd>
            </dl>
        @endif
        <x-slot:footer>
            @if ($previewContent)
                @can('contents.manage')
                    <a href="{{ route('contents.edit', $previewContent) }}" wire:navigate class="btn btn-secondary"><x-icon name="pencil" class="size-4" /> Edit</a>
                @endcan
            @endif
            <button type="button" class="btn btn-primary" x-on:click="show = false">Close</button>
        </x-slot:footer>
    </x-modal>
</div>
