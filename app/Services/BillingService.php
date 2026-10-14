<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\User;
use App\Notifications\AdminAlert;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Manual-payment subscriptions: customers submit a purchase request with
 * their payment reference, staff verify the payment and approve or reject.
 */
class BillingService
{
    public const CONTACT_FIELDS = [
        'contact_name' => 'Full name',
        'contact_email' => 'E-mail',
        'contact_phone' => 'Mobile number',
        'contact_whatsapp' => 'WhatsApp number',
    ];

    public const FIELD_MODES = ['required' => 'Required', 'optional' => 'Optional', 'hidden' => 'Hidden'];

    public const PHONE_PATTERN = '/^\+?[0-9][0-9 ()-]{6,24}$/';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly AdminNotifier $notifier,
    ) {}

    /**
     * Contact field => required | optional | hidden (Settings → Registration & billing).
     *
     * @return array<string, string>
     */
    public function contactFields(): array
    {
        $fields = [];

        foreach (array_keys(self::CONTACT_FIELDS) as $field) {
            $mode = (string) $this->settings->get('billing.'.$field);
            $fields[$field] = array_key_exists($mode, self::FIELD_MODES) ? $mode : 'optional';
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    public function orderRules(): array
    {
        $rules = [
            'payment_method_id' => ['required', 'integer', Rule::exists('payment_methods', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'transaction_reference' => ['required', 'string', 'min:4', 'max:191'],
            'proof' => ['nullable', 'image', 'max:5120'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
            'confirm' => ['accepted'],
        ];

        foreach ($this->contactFields() as $field => $mode) {
            if ($mode === 'hidden') {
                continue;
            }

            $rules[$field] = array_merge([$mode === 'required' ? 'required' : 'nullable', 'string'], match ($field) {
                'contact_name' => ['max:120'],
                'contact_email' => ['email', 'max:191'],
                default => ['max:30', 'regex:'.self::PHONE_PATTERN],
            });
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $data  Validated with orderRules().
     */
    public function submit(User $user, Plan $plan, array $data, ?UploadedFile $proof = null): PlanOrder
    {
        if (! $plan->is_active || $plan->isFree()) {
            throw ValidationException::withMessages(['plan' => 'This plan cannot be purchased.']);
        }

        if ($user->planOrders()->pending()->exists()) {
            throw ValidationException::withMessages(['plan' => 'You already have a request waiting for review. Wait for it, or cancel it on the Billing page, before sending another.']);
        }

        $method = PaymentMethod::query()->available()->findOrFail($data['payment_method_id']);

        $order = new PlanOrder([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_method_id' => $method->id,
            'status' => OrderStatus::Pending,
            'amount_usd' => $plan->price_usd,
            'months' => 1,
            'payment_method_name' => $method->name,
            'payment_address' => $method->address,
            'transaction_reference' => trim($data['transaction_reference']),
            'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'contact_whatsapp' => $data['contact_whatsapp'] ?? null,
            'customer_note' => $data['customer_note'] ?? null,
        ]);

        if ($proof) {
            $order->proof_path = $proof->storeAs('payment-proofs/'.now()->format('Y/m'), Str::uuid().'.'.strtolower($proof->extension() ?: 'jpg'), ['disk' => 'local']);
        }

        $order->save();
        $order->setRelation('user', $user)->setRelation('plan', $plan);

        $this->audit->log('order.submitted', $order, "Plan request #{$order->id}: {$plan->name} (\${$order->amount_usd})", ['reference' => $order->transaction_reference], $user);
        $this->notifier->toStaff(AdminAlert::orderSubmitted($order), Permissions::ORDERS_MANAGE);

        return $order;
    }

    /**
     * Payment verified: activate the plan for one month (renewing the same,
     * still valid plan adds a month to its current expiry).
     */
    public function approve(PlanOrder $order, User $admin, ?string $note = null): PlanOrder
    {
        $order = DB::transaction(function () use ($order, $admin, $note) {
            $order = PlanOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->isPending()) {
                throw ValidationException::withMessages(['order' => "This request is already {$order->status->value}."]);
            }

            $user = User::query()->whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();
            $renewal = (int) $user->plan_id === (int) $order->plan_id && $user->plan_expires_at?->isFuture();
            $starts = $renewal ? $user->plan_expires_at : $now;
            $expires = $starts->addMonthsNoOverflow(max(1, (int) $order->months));

            $user->forceFill([
                'plan_id' => $order->plan_id,
                'plan_started_at' => $renewal ? ($user->plan_started_at ?? $now) : $now,
                'plan_expires_at' => $expires,
                'plan_reminder_sent_at' => null,
            ])->save();

            $order->forceFill([
                'status' => OrderStatus::Approved,
                'reviewed_by' => $admin->id,
                'reviewed_at' => $now,
                'starts_at' => $starts,
                'expires_at' => $expires,
                'admin_note' => $note ?: $order->admin_note,
            ])->save();

            return $order;
        });

        $order->load(['user', 'plan']);
        $this->audit->log('order.approved', $order, "Plan request #{$order->id} approved: {$order->plan->name} for {$order->user->email} until {$order->expires_at->toDateString()}", [], $admin);
        $this->notifier->toUser($order->user, AdminAlert::orderApproved($order));

        return $order;
    }

    public function reject(PlanOrder $order, User $admin, string $reason): PlanOrder
    {
        $order = DB::transaction(function () use ($order, $admin, $reason) {
            $order = PlanOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->isPending()) {
                throw ValidationException::withMessages(['order' => "This request is already {$order->status->value}."]);
            }

            $order->forceFill([
                'status' => OrderStatus::Rejected,
                'reviewed_by' => $admin->id,
                'reviewed_at' => CarbonImmutable::now(),
                'admin_note' => $reason,
            ])->save();

            return $order;
        });

        $order->load(['user', 'plan']);
        $this->audit->log('order.rejected', $order, "Plan request #{$order->id} rejected", ['reason' => $reason], $admin);
        $this->notifier->toUser($order->user, AdminAlert::orderRejected($order));

        return $order;
    }

    /**
     * The customer withdraws a request that has not been reviewed yet.
     */
    public function cancel(PlanOrder $order, User $user): void
    {
        $updated = PlanOrder::query()
            ->whereKey($order->id)
            ->where('user_id', $user->id)
            ->where('status', OrderStatus::Pending)
            ->update(['status' => OrderStatus::Cancelled, 'updated_at' => now()]);

        if ($updated !== 1) {
            throw ValidationException::withMessages(['order' => 'Only your own pending requests can be cancelled.']);
        }

        $this->audit->log('order.cancelled', $order, "Plan request #{$order->id} cancelled by the customer", [], $user);
    }

    /**
     * Staff set a plan by hand (gift, correction, offline payment).
     */
    public function assignPlan(User $user, Plan $plan, ?CarbonImmutable $expiresAt, User $admin): void
    {
        $user->forceFill([
            'plan_id' => $plan->id,
            'plan_started_at' => CarbonImmutable::now(),
            'plan_expires_at' => $plan->is_default ? null : $expiresAt,
            'plan_reminder_sent_at' => null,
        ])->save();

        $this->audit->log('user.plan_changed', $user, "Plan of {$user->email} set to {$plan->name}".($expiresAt && ! $plan->is_default ? " until {$expiresAt->toDateString()}" : ''), [], $admin);
    }

    /**
     * Move accounts whose paid plan ran out back to the free plan.
     */
    public function expireDuePlans(): int
    {
        $fallback = Plan::fallback();
        $count = 0;

        User::query()
            ->with('plan')
            ->whereNotNull('plan_expires_at')
            ->where('plan_expires_at', '<=', now())
            ->each(function (User $user) use ($fallback, &$count) {
                $planName = $user->plan?->name ?? 'paid';

                $user->forceFill([
                    'plan_id' => $fallback?->id,
                    'plan_started_at' => CarbonImmutable::now(),
                    'plan_expires_at' => null,
                    'plan_reminder_sent_at' => null,
                ])->save();

                $this->audit->log('user.plan_expired', $user, "{$planName} plan of {$user->email} expired");
                $this->notifier->toUser($user, AdminAlert::planExpired($planName, $fallback?->name));
                $count++;
            });

        return $count;
    }

    /**
     * One reminder per paid period, a few days before it ends.
     */
    public function sendExpiryReminders(int $daysBefore = 3): int
    {
        $count = 0;

        User::query()
            ->with('plan')
            ->whereNotNull('plan_expires_at')
            ->whereNull('plan_reminder_sent_at')
            ->whereBetween('plan_expires_at', [now(), now()->addDays($daysBefore)])
            ->each(function (User $user) use (&$count) {
                $days = max(1, (int) ceil(now()->diffInHours($user->plan_expires_at) / 24));
                $this->notifier->toUser($user, AdminAlert::planExpiring($user, $days));
                $user->forceFill(['plan_reminder_sent_at' => CarbonImmutable::now()])->save();
                $count++;
            });

        return $count;
    }
}
