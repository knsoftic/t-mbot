{{-- Plan cards. Public visitors are sent to sign-up; signed-in customers to checkout. --}}
@props(['plans', 'currentPlanId' => null, 'currentExpires' => null])
<div {{ $attributes->merge(['class' => 'grid gap-4 sm:grid-cols-2 xl:grid-cols-4']) }}>
    @foreach ($plans as $plan)
        @php $isCurrent = $currentPlanId !== null && (int) $currentPlanId === $plan->id; @endphp
        <div wire:key="plan-{{ $plan->id }}" @class([
            'card relative flex flex-col p-5',
            'ring-2! ring-brand-500!' => $plan->is_featured,
        ])>
            @if ($plan->is_featured)
                <span class="absolute -top-2.5 left-5 rounded-full bg-brand-600 px-2.5 py-0.5 text-[11px] font-semibold text-white">Most popular</span>
            @endif
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-base font-semibold text-slate-900 dark:text-white">{{ $plan->name }}</h3>
                @if ($isCurrent)
                    <x-badge color="green">Current</x-badge>
                @endif
            </div>
            @if ($plan->description)
                <p class="mt-1 text-xs text-slate-500">{{ $plan->description }}</p>
            @endif
            <div class="mt-4 flex items-baseline gap-1">
                <span class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $plan->formattedPrice() }}</span>
                @unless ($plan->isFree())
                    <span class="text-sm text-slate-500">USD / month</span>
                @endunless
            </div>

            <ul class="mt-4 flex-1 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                @foreach ($plan->highlights() as $line)
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-emerald-500" /> <span>{{ $line }}</span></li>
                @endforeach
            </ul>

            <div class="mt-5">
                @guest
                    <a href="{{ route('register') }}" @class(['btn w-full', 'btn-primary' => $plan->is_featured, 'btn-secondary' => ! $plan->is_featured])>{{ $plan->isFree() ? 'Start free' : 'Get started' }}</a>
                @else
                    @if ($plan->isFree())
                        <span class="btn btn-ghost w-full cursor-default">{{ $isCurrent ? 'Your current plan' : 'Included for everyone' }}</span>
                    @else
                        <a href="{{ route('billing.checkout', $plan) }}" wire:navigate @class(['btn w-full', 'btn-primary' => $plan->is_featured || $isCurrent, 'btn-secondary' => ! $plan->is_featured && ! $isCurrent])>
                            {{ $isCurrent ? 'Renew (+1 month)' : 'Choose '.$plan->name }}
                        </a>
                    @endif
                @endguest
            </div>
        </div>
    @endforeach
</div>
