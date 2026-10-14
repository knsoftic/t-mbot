<?php

namespace App\Models;

use App\Enums\ImportRowStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person from an uploaded list (access goes through the owning import).
 */
#[Fillable(['member_import_id', 'row_number', 'input', 'telegram_user_id', 'username', 'status', 'reason', 'processed_at'])]
class MemberImportRow extends Model
{
    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'status' => ImportRowStatus::class,
            'processed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MemberImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(MemberImport::class, 'member_import_id');
    }

    public function display(): string
    {
        return $this->username ? '@'.$this->username : (string) ($this->telegram_user_id ?? $this->input);
    }
}
