<?php

namespace App\Livewire\Admin;

use App\Enums\OrderStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\PlanOrder;
use App\Services\BillingService;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Plan purchase requests: verify the manual payment, contact the buyer,
 * approve or reject.
 */
#[Title('Purchase requests')]
class Orders extends Component
{
    use InteractsWithUi, WithPagination;

    #[Url(except: 'pending')]
    public string $status = 'pending';

    #[Url(except: '')]
    public string $search = '';

    /** Request shown in the detail dialog (?order=ID opens it, e.g. from a notification). */
    #[Url(as: 'order', except: null)]
    public ?int $viewing = null;

    public bool $showDetail = false;

    public string $note = '';

    public string $reason = '';

    public function mount(): void
    {
        $this->authorize(Permissions::ORDERS_MANAGE);
        $this->showDetail = $this->viewing !== null;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }

        if ($property === 'showDetail' && ! $this->showDetail) {
            $this->viewing = null;
        }
    }

    public function open(int $id): void
    {
        $this->authorize(Permissions::ORDERS_MANAGE);
        $this->viewing = $id;
        $this->reset(['note', 'reason']);
        $this->resetValidation();
        $this->showDetail = true;
    }

    public function approve(BillingService $billing): void
    {
        $this->authorize(Permissions::ORDERS_MANAGE);
        $this->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $order = PlanOrder::query()->findOrFail($this->viewing);

        if ($this->attempt(fn () => $billing->approve($order, auth()->user(), $this->note ?: null), 'Payment approved — the plan is active and the customer was notified.')) {
            $this->showDetail = false;
            $this->viewing = null;
        }
    }

    public function reject(BillingService $billing): void
    {
        $this->authorize(Permissions::ORDERS_MANAGE);
        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [], ['reason' => 'reason']);
        $order = PlanOrder::query()->findOrFail($this->viewing);

        if ($this->attempt(fn () => $billing->reject($order, auth()->user(), $this->reason), 'Request rejected and the customer was notified.')) {
            $this->showDetail = false;
            $this->viewing = null;
        }
    }

    public function render()
    {
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

        $orders = PlanOrder::query()
            ->with(['user:id,name,email', 'plan:id,name'])
            ->when($this->status !== 'all', fn (Builder $q) => $q->where('status', $this->status))
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('transaction_reference', 'like', $term)
                ->orWhere('contact_name', 'like', $term)
                ->orWhere('contact_email', 'like', $term)
                ->orWhere('contact_whatsapp', 'like', $term)
                ->orWhere('contact_phone', 'like', $term)
                ->orWhereHas('user', fn (Builder $u) => $u->where('email', 'like', $term)->orWhere('name', 'like', $term))))
            ->latest('id')
            ->paginate(20);

        return view('livewire.admin.orders', [
            'orders' => $orders,
            'counts' => PlanOrder::query()->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status'),
            'detail' => $this->showDetail && $this->viewing
                ? PlanOrder::query()->with(['user.plan', 'plan', 'reviewer:id,name', 'paymentMethod'])->find($this->viewing)
                : null,
            'statuses' => OrderStatus::options(),
        ]);
    }
}
