<div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" wire:poll.30s.visible>
    <button type="button" class="btn-icon relative" x-on:click="open = !open" aria-label="Notifications">
        <x-icon name="bell" class="size-6" />
        @if ($unread > 0)
            <span class="absolute -right-0.5 -top-0.5 flex min-w-4.5 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold leading-4.5 text-white">{{ $unread > 99 ? '99+' : $unread }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak x-transition class="dropdown w-80 sm:w-96">
        <div class="flex items-center justify-between px-3 py-2">
            <span class="text-sm font-semibold text-slate-900 dark:text-white">Notifications</span>
            @if ($unread > 0)
                <button type="button" wire:click="markAllRead" class="text-xs font-medium text-brand-600 hover:text-brand-500">Mark all read</button>
            @endif
        </div>
        <div class="max-h-96 overflow-y-auto">
            @forelse ($items as $item)
                @php $level = $item->data['level'] ?? 'info'; @endphp
                <button type="button" wire:click="open('{{ $item->id }}')" wire:key="n-{{ $item->id }}"
                        class="dropdown-item items-start {{ $item->read_at ? 'opacity-60' : '' }}">
                    <span @class([
                        'mt-0.5',
                        'text-red-500' => $level === 'danger',
                        'text-amber-500' => $level === 'warning',
                        'text-emerald-500' => $level === 'success',
                        'text-brand-500' => $level === 'info',
                    ])><x-icon :name="$level === 'success' ? 'check-circle' : ($level === 'info' ? 'info' : 'warning')" class="size-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium">{{ $item->data['title'] ?? 'Notification' }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ str($item->data['message'] ?? '')->limit(140) }}</span>
                        <span class="block text-[11px] text-slate-400">{{ $item->created_at->diffForHumans() }}</span>
                    </span>
                    @unless ($item->read_at)
                        <span class="mt-1.5 size-2 rounded-full bg-brand-500"></span>
                    @endunless
                </button>
            @empty
                <p class="px-3 py-8 text-center text-sm text-slate-500">You're all caught up.</p>
            @endforelse
        </div>
        <div class="border-t border-slate-100 p-1 dark:border-slate-800">
            <a href="{{ route('notifications') }}" wire:navigate class="dropdown-item justify-center text-xs font-medium text-brand-600">View all notifications</a>
        </div>
    </div>
</div>
