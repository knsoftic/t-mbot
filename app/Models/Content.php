<?php

namespace App\Models;

use App\Enums\ContentSource;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\ParseMode;
use Database\Factories\ContentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'title', 'type', 'source', 'text', 'caption', 'parse_mode', 'media_url', 'media_disk', 'media_path',
    'media_mime', 'media_size', 'telegram_file_id', 'thumbnail', 'disable_notification',
    'disable_web_page_preview', 'protect_content', 'status', 'notes', 'metadata', 'created_by',
])]
class Content extends Model
{
    /** @use HasFactory<ContentFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => ContentType::class,
            'source' => ContentSource::class,
            'status' => ContentStatus::class,
            'parse_mode' => ParseMode::class,
            'metadata' => 'array',
            'disable_notification' => 'boolean',
            'disable_web_page_preview' => 'boolean',
            'protect_content' => 'boolean',
            'media_size' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Cached Telegram file ids, one per bot.
     *
     * @return HasMany<ContentBotFile, $this>
     */
    public function botFiles(): HasMany
    {
        return $this->hasMany(ContentBotFile::class);
    }

    /**
     * @return HasMany<CampaignItem, $this>
     */
    public function campaignItems(): HasMany
    {
        return $this->hasMany(CampaignItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === ContentStatus::Active;
    }

    /**
     * The text shown with the post: the message body for text posts, the caption otherwise.
     */
    public function body(): ?string
    {
        return $this->type === ContentType::Text ? $this->text : $this->caption;
    }

    /**
     * URL the admin panel can use to preview the media, if any.
     */
    public function previewUrl(): ?string
    {
        return match ($this->source) {
            ContentSource::Url => $this->media_url,
            ContentSource::Upload => $this->media_path ? route('contents.media', $this) : null,
            default => null,
        };
    }

    public function mediaLabel(): string
    {
        return match ($this->source) {
            ContentSource::Url => (string) $this->media_url,
            ContentSource::Upload => basename((string) $this->media_path),
            ContentSource::FileId => 'file_id: '.Str::limit((string) $this->telegram_file_id, 24),
            default => '—',
        };
    }

    /**
     * @param  Builder<Content>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', ContentStatus::Active);
    }

    /**
     * Server-side search used by the library and the campaign wizard.
     *
     * @param  Builder<Content>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $q) use ($term) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $q->where('title', 'like', $like)
                ->orWhere('text', 'like', $like)
                ->orWhere('caption', 'like', $like)
                ->orWhereHas('tags', fn (Builder $t) => $t->where('name', 'like', $like));
        });
    }
}
