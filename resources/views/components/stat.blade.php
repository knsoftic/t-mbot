@props(['label', 'value', 'icon' => 'chart', 'color' => 'brand', 'href' => null, 'hint' => null])
@php
    $tones = [
        'brand' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300',
        'green' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300',
        'red' => 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300',
        'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300',
        'indigo' => 'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300',
        'slate' => 'bg-slate-100 text-slate-600 dark:bg-slate-500/15 dark:text-slate-300',
        'purple' => 'bg-purple-50 text-purple-600 dark:bg-purple-500/15 dark:text-purple-300',
        'teal' => 'bg-teal-50 text-teal-600 dark:bg-teal-500/15 dark:text-teal-300',
    ];
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->merge(['class' => 'card flex items-center gap-4 p-4 '.($href ? 'transition hover:ring-brand-300 dark:hover:ring-brand-700' : '')]) }}>
    <div class="flex size-11 shrink-0 items-center justify-center rounded-xl {{ $tones[$color] ?? $tones['brand'] }}">
        <x-icon :name="$icon" class="size-5" />
    </div>
    <div class="min-w-0">
        <div class="truncate text-xs font-medium text-slate-500 dark:text-slate-400">{{ $label }}</div>
        <div class="text-2xl font-semibold tabular-nums text-slate-900 dark:text-white">{{ $value }}</div>
        @if ($hint)
            <div class="truncate text-xs text-slate-400">{{ $hint }}</div>
        @endif
    </div>
</{{ $tag }}>
