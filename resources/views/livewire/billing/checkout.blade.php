<div>
    <x-page-header :title="'Buy '.$plan->name" description="Pay with one of the methods below, then send us the transaction details. We verify every payment by hand.">
        <x-slot:actions>
            <a href="{{ route('billing.index') }}" wire:navigate class="btn btn-secondary"><x-icon name="chevron-left" class="size-4" /> Back to plans</a>
        </x-slot:actions>
    </x-page-header>

    @if ($hasPending)
        <x-docs.callout type="warning" class="mb-4">You already have a request waiting for review. Wait for it, or cancel it on the <a href="{{ route('billing.index') }}" wire:navigate class="underline">Billing page</a>, before sending another.</x-docs.callout>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <form wire:submit="submit" class="space-y-6 lg:col-span-2">
            {{-- 1. Payment method --}}
            <section class="card p-5">
                <h2 class="card-title">1. Choose a payment method</h2>
                @if ($methods->isEmpty())
                    <x-docs.callout type="warning" class="mt-3">No payment method is available yet. Please contact us{{ $supportWhatsapp ? ' on WhatsApp '.$supportWhatsapp : '' }}.</x-docs.callout>
                @else
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        @foreach ($methods as $m)
                            <button type="button" wire:key="m-{{ $m->id }}" wire:click="selectMethod({{ $m->id }})" @class([
                                'flex items-center gap-3 rounded-xl p-3 text-left ring-1 transition',
                                'bg-brand-50 ring-2 ring-brand-500 dark:bg-brand-500/10' => $payment_method_id === $m->id,
                                'ring-slate-200 hover:ring-brand-300 dark:ring-slate-700' => $payment_method_id !== $m->id,
                            ])>
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800"><x-icon name="banknotes" class="size-5" /></span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold text-slate-900 dark:text-white">{{ $m->name }}</span>
                                    <span class="block truncate font-mono text-xs text-slate-500">{{ str($m->address)->limit(36) }}</span>
                                </span>
                            </button>
                        @endforeach
                    </div>
                    @error('payment_method_id') <p class="error">{{ $message }}</p> @enderror
                @endif
            </section>

            {{-- 2. Pay --}}
            @if ($method)
                <section class="card p-5" wire:key="pay-{{ $method->id }}">
                    <h2 class="card-title">2. Send the payment</h2>
                    <div class="mt-3 grid gap-4 sm:grid-cols-[1fr_auto]">
                        <div class="space-y-3">
                            <div>
                                <div class="label">Amount</div>
                                <div class="text-2xl font-bold text-slate-900 dark:text-white">${{ $plan->price_usd }} <span class="text-sm font-medium text-slate-500">USD · 1 month of {{ $plan->name }}</span></div>
                            </div>
                            <div x-data="{ copied: false }">
                                <div class="label">{{ $method->name }} — send to</div>
                                <div class="flex gap-2">
                                    <input type="text" readonly value="{{ $method->address }}" x-ref="addr" class="input font-mono text-sm">
                                    <button type="button" class="btn btn-secondary" x-on:click="navigator.clipboard.writeText($refs.addr.value); copied = true; setTimeout(() => copied = false, 1500)">
                                        <x-icon name="copy" class="size-4" /> <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                                    </button>
                                </div>
                                @if ($method->account_name)
                                    <p class="mt-1 text-xs text-slate-500">Account name: <span class="font-medium text-slate-700 dark:text-slate-300">{{ $method->account_name }}</span></p>
                                @endif
                            </div>
                            @if ($method->instructions)
                                <div class="whitespace-pre-line rounded-lg bg-slate-50 p-3 text-sm text-slate-700 dark:bg-slate-800/60 dark:text-slate-300">{{ $method->instructions }}</div>
                            @endif
                        </div>
                        @if ($method->qr_path)
                            <img src="{{ route('payment-methods.qr', $method) }}" alt="QR code for {{ $method->name }}" class="size-40 rounded-lg bg-white object-contain p-1 ring-1 ring-slate-200">
                        @endif
                    </div>
                    @if ($instructions)
                        <p class="mt-4 whitespace-pre-line text-xs text-slate-500">{{ $instructions }}</p>
                    @endif
                </section>

                {{-- 3. Details --}}
                <section class="card space-y-4 p-5">
                    <h2 class="card-title">3. Send us the payment details</h2>

                    <div class="rounded-lg bg-amber-50 p-3 text-xs text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30">
                        <strong>Please make sure all information is correct and valid.</strong> We contact you on these details to confirm the payment; wrong details delay your activation.
                        <span class="mt-0.5 block">Tamam maloomat sahi aur valid dein — isi par aap se rabta kiya jayega.</span>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach (\App\Services\BillingService::CONTACT_FIELDS as $field => $label)
                            @if ($fields[$field] !== 'hidden')
                                <x-field :label="$label" :for="$field" :required="$fields[$field] === 'required'">
                                    <input id="{{ $field }}" wire:model="{{ $field }}" class="input"
                                           type="{{ $field === 'contact_email' ? 'email' : ($field === 'contact_name' ? 'text' : 'tel') }}"
                                           @if (in_array($field, ['contact_phone', 'contact_whatsapp'])) placeholder="+92 300 1234567" @endif>
                                </x-field>
                            @endif
                        @endforeach
                    </div>

                    <x-field label="Transaction ID / reference" for="transaction_reference" required hint="The transaction hash, TID or reference number from your payment receipt.">
                        <input id="transaction_reference" type="text" wire:model="transaction_reference" class="input font-mono" maxlength="191">
                    </x-field>

                    <x-field label="Payment screenshot (optional)" for="proof" hint="JPG / PNG, up to 5 MB. Helps us verify faster.">
                        <input id="proof" type="file" wire:model="proof" accept="image/*" class="text-sm">
                        <div wire:loading wire:target="proof" class="mt-1 text-xs text-slate-500"><x-spinner /> Uploading…</div>
                        @if ($proof && method_exists($proof, 'isPreviewable') && $proof->isPreviewable())
                            <img src="{{ $proof->temporaryUrl() }}" alt="" class="mt-2 h-28 rounded-lg ring-1 ring-slate-200">
                        @endif
                    </x-field>

                    <x-field label="Note (optional)" for="customer_note">
                        <textarea id="customer_note" wire:model="customer_note" rows="2" class="input" maxlength="1000" placeholder="Anything we should know about the payment"></textarea>
                    </x-field>

                    <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <input type="checkbox" wire:model="confirm" class="checkbox mt-0.5">
                        <span>I confirm that I have sent the payment and that all the information above is correct and valid.</span>
                    </label>
                    @error('confirm') <p class="error -mt-2">{{ $message }}</p> @enderror
                    @error('plan') <p class="error">{{ $message }}</p> @enderror

                    <div class="flex justify-end border-t border-slate-100 pt-4 dark:border-slate-800">
                        <button type="submit" class="btn btn-primary" @disabled($hasPending) wire:loading.attr="disabled" wire:target="submit,proof">
                            <span wire:loading wire:target="submit"><x-spinner /></span> Send purchase request
                        </button>
                    </div>
                </section>
            @endif
        </form>

        {{-- Summary --}}
        <aside class="space-y-4">
            <div class="card p-5">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Order summary</div>
                <div class="mt-2 flex items-baseline justify-between">
                    <span class="text-base font-semibold text-slate-900 dark:text-white">{{ $plan->name }}</span>
                    <span class="text-lg font-bold text-slate-900 dark:text-white">${{ $plan->price_usd }}</span>
                </div>
                <p class="mt-1 text-xs text-slate-500">
                    @if ($isRenewal)
                        Renewal: one month is added to your current expiry date ({{ display_time(auth()->user()->plan_expires_at, 'd M Y') }}).
                    @else
                        One month, starting when the payment is approved.
                    @endif
                </p>
                <ul class="mt-4 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                    @foreach ($plan->highlights() as $line)
                        <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-emerald-500" /> {{ $line }}</li>
                    @endforeach
                </ul>
            </div>
            <x-docs.callout type="info">After you send the request, an admin checks the payment and may contact you by e-mail, phone or WhatsApp. You get a notification when the plan is active.</x-docs.callout>
        </aside>
    </div>
</div>
