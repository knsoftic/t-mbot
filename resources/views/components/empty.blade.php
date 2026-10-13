@props(['icon' => 'info', 'title' => 'Nothing here yet', 'description' => null])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-14 text-center']) }}>
    <div class="flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500">
        <x-icon :name="$icon" class="size-6" />
    </div>
    <h3 class="mt-4 text-sm font-semibold text-slate-900 dark:text-white">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-slate-500 dark:text-slate-400">{{ $description }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
