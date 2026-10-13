<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'action', 'entity_type', 'entity_id', 'description', 'ip_address', 'user_agent', 'metadata'])]
class AuditLog extends Model
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entityLabel(): string
    {
        if (! $this->entity_type) {
            return '—';
        }

        return class_basename($this->entity_type).($this->entity_id ? ' #'.$this->entity_id : '');
    }
}
