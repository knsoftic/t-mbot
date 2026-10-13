<div>
    <x-page-header title="Notifications" :description="$unread.' unread'">
        <x-slot:actions>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="unreadOnly" class="checkbox"> Unread only</label>
            <button type="button" wire:click="markAllRead" class="btn btn-secondary" @disabled($unread === 0)>Mark all read</button>
            <x-confirm action="clearRead" class="btn btn-ghost" title="Delete read notifications?" confirm-text="Delete">Clear read</x-confirm>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        @if ($notifications->isEmpty())
            <x-empty icon="bell" title="No notifications" />
        @else
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($notifications as $n)
                    @php $level = $n->data['level'] ?? 'info'; @endphp
                    <li wire:key="n-{{ $n->id }}" class="flex items-start gap-3 px-5 py-3.5 {{ $n->read_at ? 'opacity-70' : '' }}">
                        <span @class(['mt-0.5', 'text-red-500' => $level === 'danger', 'text-amber-500' => $level === 'warning', 'text-emerald-500' => $level === 'success', 'text-brand-500' => $level === 'info'])>
                            <x-icon :name="$level === 'success' ? 'check-circle' : ($level === 'info' ? 'info' : 'warning')" class="size-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold text-slate-900 dark:text-white">{{ $n->data['title'] ?? 'Notification' }}</div>
                            <div class="text-sm text-slate-600 dark:text-slate-300">{{ $n->data['message'] ?? '' }}</div>
                            <div class="mt-0.5 text-xs text-slate-400">{{ display_time($n->created_at, 'M j, g:i A') }}
                                @if (! empty($n->data['url']) && str_starts_with($n->data['url'], '/')) · <a href="{{ $n->data['url'] }}" wire:navigate class="text-brand-600 hover:underline">Open</a> @endif
                            </div>
                        </div>
                        <div class="flex gap-1">
                            @unless ($n->read_at)
                                <button type="button" wire:click="markRead('{{ $n->id }}')" class="btn-icon" title="Mark read"><x-icon name="check" class="size-4" /></button>
                            @endunless
                            <button type="button" wire:click="delete('{{ $n->id }}')" class="btn-icon hover:text-red-600" title="Delete"><x-icon name="trash" class="size-4" /></button>
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($notifications->hasPages())
                <div class="border-t border-slate-200 px-4 py-3 dark:border-slate-800">{{ $notifications->links() }}</div>
            @endif
        @endif
    </div>
</div>
