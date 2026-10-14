<div>
    <x-page-header title="Payment methods" description="How customers can pay for plans (manual payment). Customers see the address, account name, instructions and QR code at checkout.">
        <x-slot:actions>
            <a href="{{ route('admin.plans.index') }}" wire:navigate class="btn btn-secondary"><x-icon name="credit-card" class="size-4" /> Plans</a>
            <button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Add method</button>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        @if ($methods->isEmpty())
            <x-empty icon="banknotes" title="No payment methods yet" description="Add at least one, e.g. “USDT (TRC20)” with your wallet address, a bank account, JazzCash or Easypaisa.">
                <button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Add payment method</button>
            </x-empty>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Method</th><th>Address / account</th><th class="hidden md:table-cell">QR</th><th>Status</th><th class="text-right">Actions</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($methods as $method)
                            <tr wire:key="pm-{{ $method->id }}" @class(['opacity-60' => ! $method->is_active])>
                                <td>
                                    <div class="font-medium text-slate-900 dark:text-white">{{ $method->name }}</div>
                                    @if ($method->account_name)<div class="text-xs text-slate-500">{{ $method->account_name }}</div>@endif
                                </td>
                                <td class="max-w-xs break-all font-mono text-xs">{{ $method->address }}</td>
                                <td class="hidden md:table-cell">
                                    @if ($method->qr_path)
                                        <img src="{{ route('payment-methods.qr', $method) }}" alt="" class="size-10 rounded bg-white object-contain ring-1 ring-slate-200">
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                </td>
                                <td><x-badge :color="$method->is_active ? 'green' : 'slate'">{{ $method->is_active ? 'Active' : 'Off' }}</x-badge></td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        <button type="button" wire:click="toggle({{ $method->id }})" class="btn-icon" title="{{ $method->is_active ? 'Turn off' : 'Turn on' }}"><x-icon :name="$method->is_active ? 'pause' : 'play'" class="size-4" /></button>
                                        <button type="button" wire:click="edit({{ $method->id }})" class="btn-icon" title="Edit"><x-icon name="pencil" class="size-4" /></button>
                                        <x-confirm action="delete({{ $method->id }})" class="btn-icon hover:text-red-600" title="Delete payment method?" :message="'Delete “'.$method->name.'”? Customers will no longer see it.'" confirm-text="Delete">
                                            <x-icon name="trash" class="size-4" />
                                        </x-confirm>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit payment method' : 'Add payment method'" max-width="2xl">
        <form wire:submit="save" id="pm-form" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Name" for="name" required hint="e.g. USDT (TRC20), Bank transfer, JazzCash">
                    <input id="name" type="text" wire:model="name" class="input" maxlength="100">
                </x-field>
                <x-field label="Account holder name" for="account_name">
                    <input id="account_name" type="text" wire:model="account_name" class="input" maxlength="150">
                </x-field>
            </div>
            <x-field label="Address / account number" for="address" required hint="Wallet address, IBAN / account number or mobile wallet number — shown with a copy button.">
                <input id="address" type="text" wire:model="address" class="input font-mono" maxlength="500">
            </x-field>
            <x-field label="Instructions for the customer" for="instructions" hint="Network, bank name, what to write in the payment note…">
                <textarea id="instructions" wire:model="instructions" rows="3" class="input" maxlength="3000"></textarea>
            </x-field>
            <x-field label="QR code image (optional)" for="qr" hint="PNG / JPG up to 2 MB.">
                <div class="flex items-center gap-4">
                    @if ($qr && method_exists($qr, 'isPreviewable') && $qr->isPreviewable())
                        <img src="{{ $qr->temporaryUrl() }}" alt="" class="size-20 rounded bg-white object-contain ring-1 ring-slate-200">
                    @elseif ($currentQr && ! $removeQr && $editingId)
                        <img src="{{ route('payment-methods.qr', $editingId) }}" alt="" class="size-20 rounded bg-white object-contain ring-1 ring-slate-200">
                    @endif
                    <input id="qr" type="file" wire:model="qr" accept="image/*" class="text-sm">
                    @if ($currentQr && ! $qr)
                        <label class="flex items-center gap-2 text-xs"><input type="checkbox" wire:model.live="removeQr" class="checkbox"> Remove</label>
                    @endif
                </div>
            </x-field>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Order" for="sort_order"><input id="sort_order" type="number" min="0" wire:model="sort_order" class="input"></x-field>
                <label class="flex items-center gap-2 pt-6 text-sm"><input type="checkbox" wire:model="is_active" class="checkbox"> Show at checkout</label>
            </div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-secondary" x-on:click="show = false">Cancel</button>
            <button type="submit" form="pm-form" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save,qr"><span wire:loading wire:target="save"><x-spinner /></span> Save</button>
        </x-slot:footer>
    </x-modal>
</div>
