<div>
    <x-page-header title="Billing & plans" description="Your plan, how much of it you use, and plan purchases. Prices in USD per month." />

    @if ($usage)
        <x-plan-usage :usage="$usage" />
    @else
        <x-docs.callout type="info">You are signed in with a staff account: there are no plan limits for you. Customers see their plan and usage on this page.</x-docs.callout>
    @endif

    @php $pending = $orders->first(fn ($o) => $o->isPending()); @endphp
    @if ($pending)
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/30">
            <div class="text-sm text-amber-900 dark:text-amber-200">
                <div class="font-semibold">Request #{{ $pending->id }} for {{ $pending->plan?->name }} is waiting for review</div>
                <div class="mt-0.5">We are checking your payment ({{ $pending->payment_method_name }}, reference <span class="font-mono">{{ $pending->transaction_reference }}</span>). You will get a notification when it is approved.</div>
            </div>
            <x-confirm action="cancel({{ $pending->id }})" title="Cancel this request?" message="Only cancel if you have not paid, or if you will send a new request." confirm-text="Cancel request" class="btn btn-secondary btn-sm">Cancel request</x-confirm>
        </div>
    @endif

    <h2 class="mt-8 text-base font-semibold text-slate-900 dark:text-white">Plans</h2>
    <p class="mt-1 text-sm text-slate-500">Choosing your current plan again adds one month to its expiry date. A different plan starts as soon as the payment is approved.</p>
    <x-pricing-cards :plans="$plans" :current-plan-id="$usage ? $usage['plan']?->id : null" class="mt-4" />

    <section class="card mt-8">
        <div class="card-header">
            <h2 class="card-title">Your purchase requests</h2>
            @if ($supportWhatsapp || $supportEmail)
                <div class="flex flex-wrap gap-2 text-xs">
                    <span class="text-slate-500">Questions?</span>
                    @if ($supportWhatsapp)
                        <a href="https://wa.me/{{ preg_replace('/\D+/', '', $supportWhatsapp) }}" target="_blank" rel="noopener" class="font-medium text-emerald-600 hover:underline">WhatsApp {{ $supportWhatsapp }}</a>
                    @endif
                    @if ($supportEmail)
                        <a href="mailto:{{ $supportEmail }}" class="font-medium text-brand-600 hover:underline">{{ $supportEmail }}</a>
                    @endif
                </div>
            @endif
        </div>
        @if ($orders->isEmpty())
            <x-empty icon="credit-card" title="No purchases yet" description="When you buy a plan, your requests and their status appear here." />
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>#</th><th>Date</th><th>Plan</th><th>Amount</th><th>Payment</th><th>Status</th><th>Valid until</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $order)
                            <tr wire:key="o-{{ $order->id }}">
                                <td class="tabular-nums text-slate-500">{{ $order->id }}</td>
                                <td class="whitespace-nowrap">{{ display_time($order->created_at, 'd M Y, H:i') }}</td>
                                <td class="font-medium">{{ $order->plan?->name }}</td>
                                <td class="tabular-nums">${{ $order->amount_usd }}</td>
                                <td>
                                    <div>{{ $order->payment_method_name }}</div>
                                    <div class="font-mono text-xs text-slate-500">{{ str($order->transaction_reference)->limit(30) }}</div>
                                </td>
                                <td>
                                    <x-badge :color="$order->status->color()">{{ $order->status->label() }}</x-badge>
                                    @if ($order->admin_note && ! $order->isPending())
                                        <div class="mt-1 max-w-xs text-xs text-slate-500">{{ $order->admin_note }}</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">{{ $order->expires_at ? display_time($order->expires_at, 'd M Y') : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
