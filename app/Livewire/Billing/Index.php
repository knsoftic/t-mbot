<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Plan;
use App\Services\BillingService;
use App\Services\PlanLimiter;
use App\Services\SettingsService;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The customer's plan, usage, available plans and purchase requests.
 */
#[Title('Billing & plans')]
class Index extends Component
{
    use InteractsWithUi;

    public function cancel(int $orderId, BillingService $billing): void
    {
        $order = auth()->user()->planOrders()->findOrFail($orderId);
        $this->attempt(fn () => $billing->cancel($order, auth()->user()), 'Request cancelled.');
    }

    public function render()
    {
        $user = auth()->user();
        $settings = app(SettingsService::class);

        return view('livewire.billing.index', [
            'usage' => $user->isCustomer() ? app(PlanLimiter::class)->usage($user) : null,
            'plans' => Plan::query()->forSale()->get(),
            'orders' => $user->planOrders()->with('plan')->latest('id')->limit(25)->get(),
            'supportWhatsapp' => $settings->get('billing.support_whatsapp'),
            'supportEmail' => $settings->get('billing.support_email'),
        ]);
    }
}
