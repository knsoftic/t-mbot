<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('partials.head')
</head>
<body class="h-full bg-slate-100/70 font-sans text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-200" x-data="{ sidebar: false }">
    {{-- Mobile sidebar backdrop --}}
    <div x-show="sidebar" x-cloak x-transition.opacity class="fixed inset-0 z-30 bg-slate-950/60 lg:hidden" x-on:click="sidebar = false"></div>

    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-slate-900 transition-transform duration-200 lg:translate-x-0 dark:bg-slate-900/95 dark:ring-1 dark:ring-slate-800"
           :class="{ 'translate-x-0!': sidebar }">
        <div class="flex h-16 shrink-0 items-center gap-3 px-5">
            <x-brand-mark size="size-9" />
            <div class="min-w-0">
                <div class="truncate text-sm font-semibold text-white">{{ config('app.name') }}</div>
                <div class="text-[11px] text-slate-400">Telegram automation</div>
            </div>
            <button type="button" class="ml-auto text-slate-400 hover:text-white lg:hidden" x-on:click="sidebar = false" aria-label="Close menu">
                <x-icon name="x" />
            </button>
        </div>

        <nav class="flex-1 space-y-6 overflow-y-auto px-3 pb-6 pt-2">
            @include('partials.navigation')
        </nav>

        <div class="border-t border-white/10 p-3">
            <a href="{{ route('profile') }}" wire:navigate class="flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-white/5">
                <div class="flex size-8 items-center justify-center rounded-full bg-slate-700 text-xs font-semibold text-white">
                    {{ str(auth()->user()->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}
                </div>
                <div class="min-w-0">
                    <div class="truncate text-sm font-medium text-white">{{ auth()->user()->name }}</div>
                    <div class="truncate text-xs text-slate-400">{{ auth()->user()->role?->name ?? 'No role' }}</div>
                </div>
            </a>
        </div>
    </aside>

    <div class="lg:pl-64">
        {{-- Top bar --}}
        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/80 px-4 backdrop-blur sm:px-6 dark:border-slate-800 dark:bg-slate-900/80">
            <button type="button" class="btn-icon lg:hidden" x-on:click="sidebar = true" aria-label="Open menu">
                <x-icon name="menu" class="size-6" />
            </button>

            <div class="min-w-0 flex-1">
                <div class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $title ?? '' }}</div>
            </div>

            <span class="hidden items-center gap-1.5 text-xs text-slate-500 sm:flex dark:text-slate-400" title="Times are shown in this timezone">
                <x-icon name="clock" class="size-4" /> {{ display_tz() }}
            </span>

            <a href="{{ route('help.guide') }}" wire:navigate class="btn-icon" title="User Guide" aria-label="User Guide">
                <x-icon name="info" class="size-5" />
            </a>

            <button type="button" class="btn-icon" x-on:click="$store.theme.toggle()" aria-label="Toggle dark mode">
                <x-icon name="moon" class="size-5" x-show="!$store.theme.dark" />
                <x-icon name="sun" class="size-5" x-show="$store.theme.dark" x-cloak />
            </button>

            <livewire:notification-bell />

            <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                <button type="button" class="btn-icon" x-on:click="open = !open" aria-label="Account menu">
                    <x-icon name="user" class="size-6" />
                </button>
                <div x-show="open" x-cloak x-transition class="dropdown w-56">
                    <div class="px-3 py-2">
                        <div class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ auth()->user()->name }}</div>
                        <div class="truncate text-xs text-slate-500">{{ auth()->user()->email }}</div>
                    </div>
                    <div class="my-1 border-t border-slate-100 dark:border-slate-800"></div>
                    <a href="{{ route('profile') }}" wire:navigate class="dropdown-item"><x-icon name="user" class="size-4" /> Profile & password</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item"><x-icon name="logout" class="size-4" /> Sign out</button>
                    </form>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            {{ $slot }}
        </main>
    </div>

    @include('partials.toasts')
    @include('partials.confirm')
</body>
</html>
