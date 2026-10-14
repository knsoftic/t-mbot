<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Services\BillingService;
use App\Services\SettingsService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Buy a plan with a manual payment: pick a method, pay, send the reference.
 */
#[Title('Buy a plan')]
class Checkout extends Component
{
    use InteractsWithUi, WithFileUploads;

    #[Locked]
    public int $planId;

    public ?int $payment_method_id = null;

    public string $contact_name = '';

    public string $contact_email = '';

    public string $contact_phone = '';

    public string $contact_whatsapp = '';

    public string $transaction_reference = '';

    /** @var TemporaryUploadedFile|null */
    public $proof = null;

    public string $customer_note = '';

    public bool $confirm = false;

    public function mount(Plan $plan): void
    {
        abort_if(! $plan->is_active || $plan->isFree(), 404);

        $user = auth()->user();
        $this->planId = $plan->id;
        $this->contact_name = $user->name;
        $this->contact_email = $user->email;
        $this->contact_phone = (string) $user->phone;
        $this->contact_whatsapp = (string) ($user->whatsapp ?: $user->phone);

        $methods = PaymentMethod::query()->available()->pluck('id');

        if ($methods->count() === 1) {
            $this->payment_method_id = $methods->first();
        }
    }

    public function selectMethod(int $id): void
    {
        $this->payment_method_id = PaymentMethod::query()->available()->whereKey($id)->value('id');
        $this->resetValidation('payment_method_id');
    }

    public function submit(BillingService $billing): void
    {
        $data = $this->validate($billing->orderRules(), [
            'confirm.accepted' => 'Please confirm that all the information is correct and valid.',
            'payment_method_id.required' => 'Choose how you paid.',
            'contact_phone.regex' => 'Enter a valid number with country code, e.g. +92 300 1234567.',
            'contact_whatsapp.regex' => 'Enter a valid WhatsApp number with country code, e.g. +92 300 1234567.',
        ], [
            'transaction_reference' => 'transaction ID / reference',
            'contact_name' => 'name',
            'contact_email' => 'e-mail',
            'contact_phone' => 'mobile number',
            'contact_whatsapp' => 'WhatsApp number',
            'proof' => 'payment screenshot',
        ]);

        $plan = Plan::query()->findOrFail($this->planId);
        $order = null;

        if (! $this->attempt(function () use ($billing, $plan, $data, &$order) {
            $order = $billing->submit(auth()->user(), $plan, $data, $this->proof);
        })) {
            return;
        }

        $this->flash("Request #{$order->id} sent. We will verify your payment and activate {$plan->name} — you will get a notification.");
        $this->redirectRoute('billing.index', navigate: true);
    }

    public function render()
    {
        $plan = Plan::query()->findOrFail($this->planId);
        $methods = PaymentMethod::query()->available()->get();
        $user = auth()->user();
        $settings = app(SettingsService::class);

        return view('livewire.billing.checkout', [
            'plan' => $plan,
            'methods' => $methods,
            'method' => $methods->firstWhere('id', $this->payment_method_id),
            'fields' => app(BillingService::class)->contactFields(),
            'instructions' => (string) $settings->get('billing.instructions'),
            'supportWhatsapp' => $settings->get('billing.support_whatsapp'),
            'isRenewal' => (int) $user->plan_id === $plan->id && $user->plan_expires_at?->isFuture(),
            'hasPending' => $user->planOrders()->pending()->exists(),
        ]);
    }
}
