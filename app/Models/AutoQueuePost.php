<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One content item posted (or being posted) by an Auto Queue in a round.
 */
#[Fillable(['auto_queue_id', 'content_id', 'round', 'posting_log_id', 'status'])]
class AutoQueuePost extends Model
{
    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /**
     * @return BelongsTo<Content, $this>
     */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class)->withTrashed();
    }

    /**
     * @return BelongsTo<PostingLog, $this>
     */
    public function log(): BelongsTo
    {
        return $this->belongsTo(PostingLog::class, 'posting_log_id');
    }
}
