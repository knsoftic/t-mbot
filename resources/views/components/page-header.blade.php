@props(['title', 'description' => null])
<div {{ $attributes->merge(['class' => 'mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div class="min-w-0">
        @isset($breadcrumb)
            <div class="mb-1 flex items-center gap-1 text-xs text-slate-500 dark:text-slate-400">{{ $breadcrumb }}</div>
        @endisset
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $title }}</h1>
        @if ($description)
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $description }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
