@props(['type' => 'info', 'title' => null])
@php
    $styles = [
        'info' => ['bg-brand-50 text-brand-900 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-100 dark:ring-brand-500/30', 'info'],
        'tip' => ['bg-emerald-50 text-emerald-900 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-100 dark:ring-emerald-500/30', 'check-circle'],
        'warning' => ['bg-amber-50 text-amber-900 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-100 dark:ring-amber-500/30', 'warning'],
        'danger' => ['bg-red-50 text-red-900 ring-red-200 dark:bg-red-500/10 dark:text-red-100 dark:ring-red-500/30', 'x-circle'],
    ];
    [$class, $icon] = $styles[$type] ?? $styles['info'];
@endphp
<div {{ $attributes->merge(['class' => 'flex gap-3 rounded-lg p-3 text-sm ring-1 '.$class]) }}>
    <x-icon :name="$icon" class="mt-0.5 size-5 shrink-0" />
    <div class="min-w-0">
        @if ($title)<div class="font-semibold">{{ $title }}</div>@endif
        <div>{{ $slot }}</div>
    </div>
</div>
