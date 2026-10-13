{{-- One REST endpoint: method badge, path, permission, description, then the slot (params, examples). --}}
@props(['method', 'path', 'permission' => null, 'id' => null])
@php
    $colors = [
        'GET' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200',
        'POST' => 'bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200',
        'PUT' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200',
        'DELETE' => 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200',
    ];
@endphp
<div @if ($id) id="{{ $id }}" @endif class="scroll-mt-24 rounded-xl ring-1 ring-slate-200 dark:ring-slate-700">
    <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-slate-50 px-4 py-2.5 dark:border-slate-700 dark:bg-slate-800/50">
        <span class="rounded-md px-2 py-0.5 font-mono text-xs font-bold {{ $colors[$method] ?? $colors['GET'] }}">{{ $method }}</span>
        <code class="font-mono text-sm font-semibold text-slate-900 dark:text-white">/api{{ $path }}</code>
        @if ($permission)
            <span class="ml-auto inline-flex items-center gap-1 text-[11px] text-slate-500"><x-icon name="key" class="size-3.5" /> {{ $permission }}</span>
        @endif
    </div>
    <div class="docs-prose space-y-3 p-4 text-sm text-slate-700 dark:text-slate-300">
        {{ $slot }}
    </div>
</div>
