@props(['type'])
@php
    $type = $type instanceof \App\Enums\ContentType ? $type : \App\Enums\ContentType::tryFrom((string) $type);
    $icon = match ($type) {
        \App\Enums\ContentType::Image => 'photo',
        \App\Enums\ContentType::Video => 'video',
        \App\Enums\ContentType::Document => 'document',
        \App\Enums\ContentType::Audio => 'music',
        \App\Enums\ContentType::Animation => 'sparkles',
        default => 'chat',
    };
    $tone = [
        'slate' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
        'green' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
        'purple' => 'bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300',
        'amber' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
        'pink' => 'bg-pink-100 text-pink-700 dark:bg-pink-500/15 dark:text-pink-300',
        'cyan' => 'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-300',
    ][$type?->color() ?? 'slate'];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex size-9 shrink-0 items-center justify-center rounded-lg '.$tone]) }} title="{{ $type?->label() }}">
    <x-icon :name="$icon" class="size-5" />
</span>
