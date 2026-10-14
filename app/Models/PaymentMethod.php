<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A manual payment option (crypto wallet, bank account, mobile wallet…).
 */
#[Fillable(['name', 'address', 'account_name', 'instructions', 'qr_path', 'is_active', 'sort_order'])]
class PaymentMethod extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @param  Builder<PaymentMethod>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
