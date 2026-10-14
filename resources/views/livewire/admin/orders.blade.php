<div>
    <x-page-header title="Purchase requests" description="Plan purchases paid manually. Check the payment in your wallet / bank, contact the buyer if needed, then approve or reject." />

    <div class="card">
        <div class="flex flex-col gap-3 border-b border-slate-200 p-4 lg:flex-row lg:items-center dark:border-slate-800">
            <div class="flex flex-wrap gap-1">
                @foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'all' => 'All'] as $value => $label)
                    <button type="button" wire:click="$set('status', '{{ $value }}')" @class([
                        'rounded-lg px-3 py-1.5 text-sm font-medium',
                        'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200' => $status === $value,
                        'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800' => $status !== $value,
                    ])>
                        {{ $label }}
                        @if ($value !== 'all' && ($counts[$value] ?? 0) > 0)
                            <span class="ml-1 rounded-full bg-slate-200 px-1.5 text-xs tabular-nums dark:bg-slate-700">{{ $counts[$value] }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
            <div class="relative flex-1 lg:max-w-sm lg:ml-auto">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Name, e-mail, number or transaction ID…" class="input pl-9">
            </div>
        </div>

        <div class="relative">
            <div wire:loading.flex wire:target="search,status,gotoPage,nextPage,previousPage" class="absolute inset-0 z-10 items-center justify-center bg-white/60 dark:bg-slate-900/60">
                <x-spinner class="size-6 text-brand-600" />
            </div>

            @if ($orders->isEmpty())
                <x-empty icon="inbox" :title="$status === 'pending' ? 'No requests waiting' : 'No requests found'" description="New plan purchases appear here; you also get a notification for each one." />
            @else
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Customer</th>
                                <th>Plan</th>
                                <th class="hidden md:table-cell">Payment</th>
                                <th>Status</th>
                                <th class="hidden lg:table-cell">Received</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($orders as $order)
                                <tr wire:key="order-{{ $order->id }}">
                                    <td class="tabular-nums text-slate-500">{{ $order->id }}</td>
                                    <td>
                                        <div class="font-medium text-slate-900 dark:text-white">{{ $order->contact_name ?: $order->user?->name }}</div>
                                        <div class="text-xs text-slate-500">{{ $order->contact_email ?: $order->user?->email }}</div>
                                        @if ($order->contact_whatsapp || $order->contact_phone)
                                            <div class="text-xs text-slate-500">{{ $order->contact_whatsapp ?: $order->contact_phone }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="font-medium">{{ $order->plan?->name }}</div>
                                        <div class="text-xs tabular-nums text-slate-500">${{ $order->amount_usd }}</div>
                                    </td>
                                    <td class="hidden md:table-cell">
                                        <div>{{ $order->payment_method_name }}</div>
                                        <div class="font-mono text-xs text-slate-500">{{ str($order->transaction_reference)->limit(28) }}</div>
                                    </td>
                                    <td><x-badge :color="$order->status->color()">{{ $order->status->label() }}</x-badge></td>
                                    <td class="hidden whitespace-nowrap text-xs text-slate-500 lg:table-cell" title="{{ display_time($order->created_at) }}">{{ relative_time($order->created_at) }}</td>
                                    <td class="text-right">
                                        <button type="button" wire:click="open({{ $order->id }})" @class(['btn btn-sm', 'btn-primary' => $order->isPending(), 'btn-secondary' => ! $order->isPending()])>
                                            {{ $order->isPending() ? 'Review' : 'View' }}
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($orders->hasPages())
                    <div class="border-t border-slate-200 px-4 py-3 dark:border-slate-800">{{ $orders->links() }}</div>
                @endif
            @endif
        </div>
    </div>

    <x-modal wire:model="showDetail" :title="$detail ? 'Request #'.$detail->id : 'Request'" max-width="3xl">
        @if ($detail)
            @php
                $email = $detail->contact_email ?: $detail->user?->email;
                $phone = $detail->contact_phone ?: $detail->contact_whatsapp;
                $whatsapp = $detail->whatsappLink();
                $account = $detail->user;
            @endphp
            <div class="space-y-5">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <x-badge :color="$detail->status->color()">{{ $detail->status->label() }}</x-badge>
                    <span class="text-slate-500">Received {{ display_time($detail->created_at) }}</span>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-lg bg-slate-50 p-4 dark:bg-slate-800/50">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Contact details (from the form)</div>
                        <dl class="mt-2 space-y-1 text-sm">
                            <div><dt class="inline text-slate-500">Name:</dt> <dd class="inline font-medium">{{ $detail->contact_name ?: '—' }}</dd></div>
                            <div><dt class="inline text-slate-500">E-mail:</dt> <dd class="inline">{{ $detail->contact_email ?: '—' }}</dd></div>
                            <div><dt class="inline text-slate-500">Mobile:</dt> <dd class="inline">{{ $detail->contact_phone ?: '—' }}</dd></div>
                            <div><dt class="inline text-slate-500">WhatsApp:</dt> <dd class="inline">{{ $detail->contact_whatsapp ?: '—' }}</dd></div>
                        </dl>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($email)
                                <a href="mailto:{{ $email }}?subject={{ rawurlencode('Your '.$detail->plan?->name.' plan request #'.$detail->id) }}" class="btn btn-secondary btn-sm"><x-icon name="envelope" class="size-4" /> E-mail</a>
                            @endif
                            @if ($phone)
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" class="btn btn-secondary btn-sm"><x-icon name="phone" class="size-4" /> Call</a>
                            @endif
                            @if ($whatsapp)
                                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="btn btn-sm bg-emerald-600 text-white hover:bg-emerald-500"><x-icon name="chat" class="size-4" /> WhatsApp</a>
                            @endif
                        </div>
                    </div>

                    <div class="rounded-lg bg-slate-50 p-4 dark:bg-slate-800/50">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Account</div>
                        <dl class="mt-2 space-y-1 text-sm">
                            <div><dt class="inline text-slate-500">User:</dt> <dd class="inline font-medium">{{ $account?->name }}</dd></div>
                            <div><dt class="inline text-slate-500">Login e-mail:</dt> <dd class="inline">{{ $account?->email }}</dd></div>
                            <div><dt class="inline text-slate-500">Current plan:</dt> <dd class="inline">{{ $account?->currentPlan()?->name ?? 'Staff (no limits)' }}
                                @if ($account?->plan_expires_at && ! $account->planExpired()) <span class="text-slate-500">until {{ display_time($account->plan_expires_at, 'd M Y') }}</span>@endif</dd></div>
                            <div><dt class="inline text-slate-500">Registered:</dt> <dd class="inline">{{ display_time($account?->created_at, 'd M Y') }}</dd></div>
                        </dl>
                    </div>
                </div>

                <div class="rounded-lg ring-1 ring-slate-200 dark:ring-slate-700">
                    <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-slate-500">Plan</dt><dd class="font-medium">{{ $detail->plan?->name }} · {{ $detail->months }} month</dd></div>
                        <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-slate-500">Amount</dt><dd class="text-lg font-bold tabular-nums">${{ $detail->amount_usd }} USD</dd></div>
                        <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-slate-500">Method</dt><dd>{{ $detail->payment_method_name }}</dd></div>
                        <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-slate-500">Paid to</dt><dd class="break-all text-right font-mono text-xs">{{ $detail->payment_address }}</dd></div>
                        <div class="flex justify-between gap-4 px-4 py-2.5" x-data="{ copied: false }">
                            <dt class="text-slate-500">Transaction ID / reference</dt>
                            <dd class="flex items-center gap-2">
                                <span class="break-all font-mono text-xs font-semibold">{{ $detail->transaction_reference }}</span>
                                <button type="button" class="btn-icon" x-on:click="navigator.clipboard.writeText(@js($detail->transaction_reference)); copied = true; setTimeout(() => copied = false, 1500)" title="Copy">
                                    <x-icon name="copy" class="size-4" x-show="!copied" /><x-icon name="check" class="size-4 text-emerald-500" x-show="copied" x-cloak />
                                </button>
                            </dd>
                        </div>
                        @if ($detail->customer_note)
                            <div class="px-4 py-2.5"><dt class="text-slate-500">Customer note</dt><dd class="mt-1 whitespace-pre-line">{{ $detail->customer_note }}</dd></div>
                        @endif
                        @if ($detail->proof_path)
                            <div class="px-4 py-2.5">
                                <dt class="text-slate-500">Payment screenshot</dt>
                                <dd class="mt-2"><a href="{{ route('billing.proof', $detail) }}" target="_blank" rel="noopener"><img src="{{ route('billing.proof', $detail) }}" alt="Payment screenshot" class="max-h-64 rounded-lg ring-1 ring-slate-200 dark:ring-slate-700"></a></dd>
                            </div>
                        @endif
                        @if (! $detail->isPending())
                            <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-slate-500">Reviewed</dt><dd>{{ $detail->reviewer?->name ?? '—' }} · {{ display_time($detail->reviewed_at) }}</dd></div>
                            @if ($detail->expires_at)
                                <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-slate-500">Plan period</dt><dd>{{ display_time($detail->starts_at, 'd M Y') }} → {{ display_time($detail->expires_at, 'd M Y') }}</dd></div>
                            @endif
                            @if ($detail->admin_note)
                                <div class="px-4 py-2.5"><dt class="text-slate-500">Admin note</dt><dd class="mt-1 whitespace-pre-line">{{ $detail->admin_note }}</dd></div>
                            @endif
                        @endif
                    </dl>
                </div>

                @if ($detail->isPending())
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="rounded-lg bg-emerald-50 p-4 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:ring-emerald-500/30">
                            <div class="text-sm font-semibold text-emerald-900 dark:text-emerald-200">Approve</div>
                            <p class="mt-1 text-xs text-emerald-800 dark:text-emerald-300">Only after you see the payment. The plan starts now (a renewal of the same active plan adds one month to its expiry).</p>
                            <textarea wire:model="note" rows="2" class="input mt-2" placeholder="Note for the customer (optional)"></textarea>
                            @error('note') <p class="error">{{ $message }}</p> @enderror
                            <x-confirm action="approve" title="Approve this payment?" :message="'Activate '.$detail->plan?->name.' for '.($detail->contact_name ?: $account?->name).'?'" confirm-text="Approve" :danger="false" class="btn-success mt-2 w-full">
                                <x-icon name="check" class="size-4" /> Approve & activate
                            </x-confirm>
                        </div>
                        <div class="rounded-lg bg-red-50 p-4 ring-1 ring-red-200 dark:bg-red-500/10 dark:ring-red-500/30">
                            <div class="text-sm font-semibold text-red-900 dark:text-red-200">Reject</div>
                            <p class="mt-1 text-xs text-red-800 dark:text-red-300">The customer sees this reason.</p>
                            <textarea wire:model="reason" rows="2" class="input mt-2" placeholder="e.g. Payment not received with this transaction ID"></textarea>
                            @error('reason') <p class="error">{{ $message }}</p> @enderror
                            <button type="button" wire:click="reject" wire:loading.attr="disabled" wire:target="reject" class="btn btn-danger mt-2 w-full"><x-icon name="x" class="size-4" /> Reject</button>
                        </div>
                    </div>
                @endif
            </div>
        @else
            <p class="text-sm text-slate-500">This request no longer exists.</p>
        @endif
    </x-modal>
</div>
