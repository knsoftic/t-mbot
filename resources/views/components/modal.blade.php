{{-- Usage: <x-modal wire:model="showForm" title="Add bot" max-width="2xl"> ... <x-slot:footer>...</x-slot:footer></x-modal> --}}
@props(['title' => null, 'maxWidth' => '2xl'])
@php
    $model = $attributes->wire('model')->value();
    $width = ['sm' => 'sm:max-w-sm', 'md' => 'sm:max-w-md', 'lg' => 'sm:max-w-lg', 'xl' => 'sm:max-w-xl', '2xl' => 'sm:max-w-2xl', '3xl' => 'sm:max-w-3xl', '4xl' => 'sm:max-w-4xl', '5xl' => 'sm:max-w-5xl'][$maxWidth] ?? 'sm:max-w-2xl';
@endphp
<div x-data="{ show: $wire.entangle('{{ $model }}').live }" x-show="show" x-cloak
     x-on:keydown.escape.window="show = false"
     class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div x-show="show" x-transition.opacity class="fixed inset-0 bg-slate-950/60 backdrop-blur-sm" x-on:click="show = false"></div>
    <div class="relative flex min-h-full items-end justify-center sm:items-center sm:p-6">
        <div x-show="show" x-transition
             class="card relative flex max-h-[92vh] w-full flex-col rounded-b-none sm:rounded-xl {{ $width }}">
            @if ($title)
                <div class="card-header">
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ $title }}</h2>
                    <button type="button" class="btn-icon" x-on:click="show = false" aria-label="Close">
                        <x-icon name="x" />
                    </button>
                </div>
            @endif
            <div class="flex-1 overflow-y-auto px-5 py-5">
                {{ $slot }}
            </div>
            @isset($footer)
                <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-5 py-3.5 dark:border-slate-800">
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
</div>
