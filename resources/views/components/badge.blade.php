@props(['color' => 'slate', 'dot' => false])
@php
    $colors = [
        'slate' => 'bg-slate-100 text-slate-700 ring-slate-500/20 dark:bg-slate-500/15 dark:text-slate-300',
        'zinc' => 'bg-zinc-100 text-zinc-700 ring-zinc-500/20 dark:bg-zinc-500/15 dark:text-zinc-300',
        'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/15 dark:text-emerald-300',
        'red' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/15 dark:text-red-300',
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/15 dark:text-amber-300',
        'blue' => 'bg-brand-50 text-brand-700 ring-brand-600/20 dark:bg-brand-500/15 dark:text-brand-300',
        'indigo' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20 dark:bg-indigo-500/15 dark:text-indigo-300',
        'purple' => 'bg-purple-50 text-purple-700 ring-purple-600/20 dark:bg-purple-500/15 dark:text-purple-300',
        'pink' => 'bg-pink-50 text-pink-700 ring-pink-600/20 dark:bg-pink-500/15 dark:text-pink-300',
        'teal' => 'bg-teal-50 text-teal-700 ring-teal-600/20 dark:bg-teal-500/15 dark:text-teal-300',
        'cyan' => 'bg-cyan-50 text-cyan-700 ring-cyan-600/20 dark:bg-cyan-500/15 dark:text-cyan-300',
    ];
    $dots = ['green' => 'bg-emerald-500', 'red' => 'bg-red-500', 'amber' => 'bg-amber-500', 'blue' => 'bg-brand-500', 'indigo' => 'bg-indigo-500'];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap '.($colors[$color] ?? $colors['slate'])]) }}>
    @if ($dot)
        <span class="size-1.5 rounded-full {{ $dots[$color] ?? 'bg-slate-400' }} {{ in_array($color, ['green', 'blue'], true) ? 'animate-pulse' : '' }}"></span>
    @endif
    {{ $slot }}
</span>
