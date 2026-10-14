<div>
    <x-page-header title="Plans" description="Monthly subscription plans (USD). Limits apply to customer accounts; staff accounts have no limits.">
        <x-slot:actions>
            <a href="{{ route('admin.payment-methods.index') }}" wire:navigate class="btn btn-secondary"><x-icon name="banknotes" class="size-4" /> Payment methods</a>
            <button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" class="size-4" /> New plan</button>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Price</th>
                        <th class="hidden md:table-cell">Limits</th>
                        <th class="hidden lg:table-cell">Features</th>
                        <th>Accounts</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($plans as $plan)
                        <tr wire:key="plan-{{ $plan->id }}" @class(['opacity-60' => ! $plan->is_active])>
                            <td>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="font-medium text-slate-900 dark:text-white">{{ $plan->name }}</span>
                                    @if ($plan->is_default) <x-badge color="blue">Default</x-badge> @endif
                                    @if ($plan->is_featured) <x-badge color="purple">Popular</x-badge> @endif
                                    @unless ($plan->is_active) <x-badge>Hidden</x-badge> @endunless
                                </div>
                                @if ($plan->description)<div class="max-w-xs text-xs text-slate-500">{{ $plan->description }}</div>@endif
                            </td>
                            <td class="whitespace-nowrap font-semibold tabular-nums">{{ $plan->formattedPrice() }}@unless ($plan->isFree())<span class="text-xs font-normal text-slate-500"> / mo</span>@endunless</td>
                            <td class="hidden text-xs text-slate-600 md:table-cell dark:text-slate-300">
                                {{ \App\Models\Plan::formatLimit($plan->max_bots) }} bots ·
                                {{ \App\Models\Plan::formatLimit($plan->max_channels) }} channels ·
                                {{ \App\Models\Plan::formatLimit($plan->max_groups) }} groups<br>
                                {{ \App\Models\Plan::formatLimit($plan->posts_per_day) }}/day ·
                                {{ \App\Models\Plan::formatLimit($plan->posts_per_week) }}/week ·
                                {{ \App\Models\Plan::formatLimit($plan->posts_per_month) }}/month
                            </td>
                            <td class="hidden lg:table-cell">
                                <div class="flex flex-wrap gap-1">
                                    @foreach (\App\Models\Plan::FEATURES as $column => $label)
                                        <x-badge :color="$plan->{$column} ? 'green' : 'slate'">{{ $plan->{$column} ? '✓' : '✕' }} {{ $label }}</x-badge>
                                    @endforeach
                                </div>
                            </td>
                            <td class="tabular-nums">{{ $plan->users_count }}</td>
                            <td>
                                <div class="flex items-center justify-end gap-1">
                                    @if ($plan->isFree() && ! $plan->is_default)
                                        <button type="button" wire:click="makeDefault({{ $plan->id }})" class="btn btn-ghost btn-sm" title="Use for new and expired accounts">Make default</button>
                                    @endif
                                    <button type="button" wire:click="toggle({{ $plan->id }})" class="btn-icon" title="{{ $plan->is_active ? 'Hide from customers' : 'Offer to customers' }}">
                                        <x-icon :name="$plan->is_active ? 'eye' : 'play'" class="size-4" />
                                    </button>
                                    <button type="button" wire:click="edit({{ $plan->id }})" class="btn-icon" title="Edit"><x-icon name="pencil" class="size-4" /></button>
                                    <x-confirm action="delete({{ $plan->id }})" class="btn-icon hover:text-red-600" title="Delete plan?" :message="'Delete “'.$plan->name.'”? Plans with accounts or purchases cannot be deleted — hide them instead.'" confirm-text="Delete">
                                        <x-icon name="trash" class="size-4" />
                                    </x-confirm>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit plan' : 'New plan'" max-width="3xl">
        <form wire:submit="save" id="plan-form" class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-field label="Name" for="form.name" required class="sm:col-span-2"><input id="form.name" type="text" wire:model="form.name" class="input" maxlength="80"></x-field>
                <x-field label="Price (USD / month)" for="form.price_usd" required><input id="form.price_usd" type="number" step="0.01" min="0" wire:model="form.price_usd" class="input"></x-field>
            </div>
            <x-field label="Short description" for="form.description"><input id="form.description" type="text" wire:model="form.description" class="input" maxlength="255"></x-field>

            <div>
                <div class="label">Limits <span class="font-normal text-slate-500">— leave blank for unlimited</span></div>
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach ($limitLabels as $column => $label)
                        <x-field :label="$label" :for="'form.'.$column">
                            <input id="form.{{ $column }}" type="number" min="0" wire:model="form.{{ $column }}" class="input" placeholder="Unlimited">
                        </x-field>
                    @endforeach
                    <x-field label="Shortest interval (minutes)" for="form.min_interval_minutes" required hint="Interval campaigns cannot post more often.">
                        <input id="form.min_interval_minutes" type="number" min="1" wire:model="form.min_interval_minutes" class="input">
                    </x-field>
                </div>
            </div>

            <div>
                <div class="label">Features</div>
                <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                    @foreach (\App\Models\Plan::FEATURES as $column => $label)
                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.{{ $column }}" class="checkbox"> {{ $label }}</label>
                    @endforeach
                </div>
            </div>

            <x-field label="Extra selling points" for="form.extra_features" hint="One per line, shown on the pricing card (e.g. “Priority support”).">
                <textarea id="form.extra_features" wire:model="form.extra_features" rows="2" class="input"></textarea>
            </x-field>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field label="Order on the pricing page" for="form.sort_order"><input id="form.sort_order" type="number" min="0" wire:model="form.sort_order" class="input"></x-field>
                <label class="flex items-center gap-2 pt-6 text-sm"><input type="checkbox" wire:model="form.is_active" class="checkbox"> Offer to customers</label>
                <label class="flex items-center gap-2 pt-6 text-sm"><input type="checkbox" wire:model="form.is_featured" class="checkbox"> Highlight as “Most popular”</label>
            </div>

            <p class="text-xs text-slate-500">Changes apply at once to every account on this plan (lower limits do not delete anything; they only stop new bots, channels, posts… above the limit).</p>
        </form>

        <x-slot:footer>
            <button type="button" class="btn btn-secondary" x-on:click="show = false">Cancel</button>
            <button type="submit" form="plan-form" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save"><span wire:loading wire:target="save"><x-spinner /></span> Save plan</button>
        </x-slot:footer>
    </x-modal>
</div>
