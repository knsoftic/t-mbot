{{-- Current plan with usage bars. $usage comes from PlanLimiter::usage(). --}}
@props(['usage', 'compact' => false])
@php
    $user = auth()->user();
    $plan = $usage['plan'];
    $rows = $compact
        ? array_merge(array_slice($usage['sending'], 0, 1), array_slice($usage['resources'], 0, 3))
        : array_merge($usage['sending'], $usage['resources']);
@endphp
<div {{ $attributes->merge(['class' => 'card p-5']) }}>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Your plan</div>
            <div class="mt-0.5 flex items-center gap-2">
                <span class="text-lg font-semibold text-slate-900 dark:text-white">{{ $plan?->name ?? 'No plan' }}</span>
                @if ($plan && ! $plan->isFree())
                    <span class="text-sm text-slate-500">{{ $plan->formattedPrice() }} / month</span>
                @endif
            </div>
            <div class="mt-0.5 text-xs text-slate-500">
                @if ($user->plan_expires_at && ! $user->planExpired())
                    Active until {{ display_time($user->plan_expires_at, 'd M Y, H:i') }}
                    ({{ $user->plan_expires_at->diffForHumans() }})
                @elseif ($user->planExpired())
                    <span class="text-red-600">Your paid plan expired — free plan limits apply.</span>
                @else
                    No expiry
                @endif
            </div>
        </div>
        <a href="{{ route('billing.index') }}" wire:navigate class="btn btn-primary btn-sm"><x-icon name="sparkles" class="size-4" /> {{ $plan && ! $plan->isFree() ? 'Renew / upgrade' : 'Upgrade plan' }}</a>
    </div>

    <div @class(['mt-4 grid gap-x-6 gap-y-3', 'sm:grid-cols-2 lg:grid-cols-4' => $compact, 'sm:grid-cols-2' => ! $compact])>
        @foreach ($rows as $row)
            @php
                $limit = $row['limit'];
                $percent = $limit ? min(100, (int) round($row['used'] / max(1, $limit) * 100)) : 0;
                $tone = $limit === null ? 'bg-emerald-500' : ($percent >= 100 ? 'bg-red-500' : ($percent >= 80 ? 'bg-amber-500' : 'bg-brand-500'));
            @endphp
            <div>
                <div class="flex items-baseline justify-between text-xs">
                    <span class="font-medium text-slate-600 dark:text-slate-300">{{ $row['label'] }}</span>
                    <span class="tabular-nums text-slate-500">{{ number_format($row['used']) }} / {{ \App\Models\Plan::formatLimit($limit) }}</span>
                </div>
                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                    <div class="h-full rounded-full {{ $tone }}" style="width: {{ $limit === null ? 100 : max(2, $percent) }}%"></div>
                </div>
                @if (isset($row['resets_at']) && $limit !== null && $row['used'] >= $limit)
                    <div class="mt-0.5 text-[11px] text-red-600">Limit reached — posting resumes {{ display_time($row['resets_at'], 'd M, H:i') }}</div>
                @endif
            </div>
        @endforeach
    </div>
</div>
