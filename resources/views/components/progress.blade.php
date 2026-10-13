@props(['percent' => 0, 'color' => 'brand'])
@php $bar = ['brand' => 'bg-brand-500', 'green' => 'bg-emerald-500', 'amber' => 'bg-amber-500', 'red' => 'bg-red-500'][$color] ?? 'bg-brand-500'; @endphp
<div {{ $attributes->merge(['class' => 'h-2 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800']) }} role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
    <div class="h-full rounded-full {{ $bar }} transition-all duration-500" style="width: {{ max(0, min(100, (int) $percent)) }}%"></div>
</div>
