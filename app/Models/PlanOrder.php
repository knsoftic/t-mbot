<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan purchase request paid manually; an admin approves or rejects it.
 */
#[Fillable([
    'user_id', 'plan_id', 'payment_method_id', 'status', 'amount_usd', 'months', 'payment_method_name',
    'payment_address', 'transaction_reference', 'proof_path', 'contact_name', 'contact_email',
    'contact_phone', 'contact_whatsapp', 'customer_note',
])]
class PlanOrder extends Model
{
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'amount_usd' => 'decimal:2',
            'reviewed_at' => 'immutable_datetime',
            'starts_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<PlanOrder>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', OrderStatus::Pending);
    }

    public function isPending(): bool
    {
        return $this->status === OrderStatus::Pending;
    }

    /**
     * wa.me link (digits only, international format) with a short greeting.
     */
    public function whatsappLink(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($this->contact_whatsapp ?: $this->contact_phone));

        if ($digits === '' || strlen($digits) < 8) {
            return null;
        }

        $text = "Hello {$this->contact_name}, about your {$this->plan?->name} plan request #{$this->id}";

        return 'https://wa.me/'.$digits.'?text='.rawurlencode($text);
    }
}
