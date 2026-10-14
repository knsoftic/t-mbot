<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\PaymentMethod;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Manual payment options shown at checkout (wallet addresses, bank accounts…).
 */
#[Title('Payment methods')]
class PaymentMethods extends Component
{
    use InteractsWithUi, WithFileUploads;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $address = '';

    public string $account_name = '';

    public string $instructions = '';

    public bool $is_active = true;

    public int $sort_order = 0;

    /** @var TemporaryUploadedFile|null */
    public $qr = null;

    public bool $removeQr = false;

    public ?string $currentQr = null;

    public function mount(): void
    {
        $this->authorize(Permissions::PLANS_MANAGE);
    }

    public function create(): void
    {
        $this->authorize(Permissions::PLANS_MANAGE);
        $this->resetForm();
        $this->sort_order = (int) PaymentMethod::query()->max('sort_order') + 1;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Permissions::PLANS_MANAGE);
        $method = PaymentMethod::query()->findOrFail($id);
        $this->resetForm();
        $this->editingId = $method->id;
        $this->fill($method->only(['name', 'address', 'is_active', 'sort_order']) + [
            'account_name' => (string) $method->account_name,
            'instructions' => (string) $method->instructions,
        ]);
        $this->currentQr = $method->qr_path;
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize(Permissions::PLANS_MANAGE);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:500'],
            'account_name' => ['nullable', 'string', 'max:150'],
            'instructions' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000'],
            'qr' => ['nullable', 'image', 'max:2048'],
        ], [], ['address' => 'address / account number', 'qr' => 'QR image']);

        $method = $this->editingId ? PaymentMethod::query()->findOrFail($this->editingId) : new PaymentMethod;
        $method->fill([
            'name' => trim($data['name']),
            'address' => trim($data['address']),
            'account_name' => $data['account_name'] ?: null,
            'instructions' => $data['instructions'] ?: null,
            'is_active' => $data['is_active'],
            'sort_order' => $data['sort_order'],
        ]);

        if ($this->qr || $this->removeQr) {
            if ($method->qr_path) {
                Storage::disk('local')->delete($method->qr_path);
            }

            $method->qr_path = $this->qr
                ? $this->qr->storeAs('payment-qr', Str::uuid().'.'.strtolower($this->qr->extension() ?: 'png'), ['disk' => 'local'])
                : null;
        }

        $method->save();
        $audit->log($this->editingId ? 'payment_method.updated' : 'payment_method.created', $method, "Payment method “{$method->name}” saved");

        $this->showForm = false;
        $this->resetForm();
        $this->toast("Payment method “{$method->name}” saved.");
    }

    public function toggle(int $id, AuditLogger $audit): void
    {
        $this->authorize(Permissions::PLANS_MANAGE);
        $method = PaymentMethod::query()->findOrFail($id);
        $method->update(['is_active' => ! $method->is_active]);
        $audit->log('payment_method.updated', $method, "Payment method “{$method->name}” ".($method->is_active ? 'enabled' : 'disabled'));
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $this->authorize(Permissions::PLANS_MANAGE);
        $method = PaymentMethod::query()->findOrFail($id);

        // Soft delete: purchase requests keep a copy of the name and address.
        $method->delete();
        $audit->log('payment_method.deleted', $method, "Payment method “{$method->name}” deleted");
        $this->toast('Payment method deleted. Earlier purchase requests keep their payment details.');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'address', 'account_name', 'instructions', 'qr', 'removeQr', 'currentQr']);
        $this->is_active = true;
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.admin.payment-methods', [
            'methods' => PaymentMethod::query()->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }
}
