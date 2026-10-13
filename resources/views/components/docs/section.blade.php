{{-- A documentation section with an anchor for the table of contents. --}}
@props(['id', 'title', 'icon' => null])
<section id="{{ $id }}" {{ $attributes->merge(['class' => 'card scroll-mt-24 p-6']) }}>
    <h2 class="flex items-center gap-2 text-lg font-semibold text-slate-900 dark:text-white">
        @if ($icon)
            <span class="flex size-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><x-icon :name="$icon" class="size-4" /></span>
        @endif
        <a href="#{{ $id }}" class="hover:text-brand-600">{{ $title }}</a>
    </h2>
    <div class="docs-prose mt-4 space-y-4 text-sm leading-relaxed text-slate-700 dark:text-slate-300">
        {{ $slot }}
    </div>
</section>
