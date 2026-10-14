<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Enums\ContentSource;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\ParseMode;
use App\Models\Content;
use App\Models\Tag;
use App\Models\User;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContentService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly MediaUrlValidator $urlValidator,
        private readonly AuditLogger $audit,
        private readonly PlanLimiter $limiter,
    ) {}

    /**
     * Validation rules shared by the admin panel and the API.
     *
     * @return array<string, mixed>
     */
    public function rules(?string $type, ?string $source, bool $hasStoredUpload = false): array
    {
        $type = ContentType::tryFrom((string) $type);
        $source = ContentSource::tryFrom((string) $source);
        $isText = $type === ContentType::Text;

        return [
            'title' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::enum(ContentType::class)],
            'source' => ['required', Rule::enum(ContentSource::class), function (string $attr, $value, Closure $fail) use ($isText) {
                if ($isText && $value !== ContentSource::None->value) {
                    $fail('Text content has no media source.');
                }

                if (! $isText && $value === ContentSource::None->value) {
                    $fail('Choose where the media comes from.');
                }
            }],
            'text' => [$isText ? 'required' : 'nullable', 'string', 'max:4096'],
            'caption' => ['nullable', 'string', 'max:1024'],
            'parse_mode' => ['nullable', Rule::enum(ParseMode::class)],
            'media_url' => [
                Rule::requiredIf(! $isText && $source === ContentSource::Url), 'nullable', 'string', 'max:2048',
                function (string $attr, $value, Closure $fail) use ($source, $isText) {
                    if (! $isText && $source === ContentSource::Url && $value && ($error = $this->urlValidator->validate($value))) {
                        $fail($error);
                    }
                },
            ],
            'telegram_file_id' => [Rule::requiredIf(! $isText && $source === ContentSource::FileId), 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
            'upload' => array_values(array_filter([
                Rule::requiredIf(! $isText && $source === ContentSource::Upload && ! $hasStoredUpload),
                'nullable',
                'file',
                $type && $type->isMedia() ? 'extensions:'.implode(',', $type->allowedExtensions()) : null,
                $type && $type->isMedia() ? 'max:'.$type->maxUploadKilobytes() : null,
            ])),
            'thumbnail' => ['nullable', 'url:https', 'max:2048'],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
            'status' => ['required', Rule::enum(ContentStatus::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
            'disable_notification' => ['boolean'],
            'disable_web_page_preview' => ['boolean'],
            'protect_content' => ['boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  Validated data.
     */
    public function create(array $data, ?UploadedFile $upload = null, ?User $user = null): Content
    {
        $this->limiter->assertCanAdd($user, 'max_contents', 'title');
        $this->assertUploadAllowed($user?->id, $upload);

        $content = DB::transaction(function () use ($data, $upload, $user) {
            $content = new Content($this->attributes($data));
            $content->user_id = $user?->id;
            $content->created_by = $user?->id;

            if ($upload) {
                $this->storeUpload($content, $upload);
            }

            $content->save();
            $content->tags()->sync(Tag::idsFor($data['tags'] ?? [], $content->user_id));

            return $content;
        });

        $this->audit->log('content.created', $content, "Content \"{$content->title}\" created", ['type' => $content->type->value], $user);

        return $content;
    }

    /**
     * @param  array<string, mixed>  $data  Validated data.
     */
    public function update(Content $content, array $data, ?UploadedFile $upload = null, ?User $user = null): Content
    {
        $this->assertUploadAllowed($content->user_id, $upload);

        DB::transaction(function () use ($content, $data, $upload) {
            $content->fill($this->attributes($data));

            if ($upload) {
                $this->storeUpload($content, $upload);
            }

            // Different media means previously cached Telegram file ids are no longer valid.
            if ($upload || $content->isDirty(['type', 'source', 'media_url', 'telegram_file_id', 'media_path'])) {
                $content->botFiles()->delete();
            }

            $content->save();
            $content->tags()->sync(Tag::idsFor($data['tags'] ?? [], $content->user_id));
        });

        $this->audit->log('content.updated', $content, "Content \"{$content->title}\" edited", $this->audit->changes($content), $user);

        return $content;
    }

    public function duplicate(Content $content, ?User $user = null): Content
    {
        // The copy stays in the same account as the original.
        $this->limiter->assertCanAdd($content->user_id, 'max_contents', 'content');

        $copy = DB::transaction(function () use ($content, $user) {
            $copy = $content->replicate(['created_by']);
            $copy->title = Str::limit($content->title, 190, '').' (copy)';
            $copy->created_by = $user?->id;

            // Give the copy its own file so deleting one never breaks the other.
            if ($content->media_path && Storage::disk($content->media_disk ?: 'local')->exists($content->media_path)) {
                $newPath = $this->directory().'/'.Str::uuid().'.'.pathinfo($content->media_path, PATHINFO_EXTENSION);
                Storage::disk($content->media_disk ?: 'local')->copy($content->media_path, $newPath);
                $copy->media_path = $newPath;
            }

            $copy->save();
            $copy->tags()->sync($content->tags()->pluck('tags.id'));

            return $copy;
        });

        $this->audit->log('content.duplicated', $copy, "Content \"{$content->title}\" duplicated", ['source_id' => $content->id], $user);

        return $copy;
    }

    public function setStatus(Content $content, ContentStatus $status, ?User $user = null): void
    {
        $content->update(['status' => $status]);
        $this->audit->log('content.'.($status === ContentStatus::Active ? 'activated' : 'deactivated'), $content, "Content \"{$content->title}\" {$status->value}", [], $user);
    }

    public function delete(Content $content, ?User $user = null): void
    {
        $campaigns = $content->campaignItems()
            ->whereHas('campaign', fn ($q) => $q->whereIn('status', [CampaignStatus::Draft, CampaignStatus::Scheduled, CampaignStatus::Running, CampaignStatus::Paused]))
            ->with('campaign:id,name')
            ->get()
            ->pluck('campaign.name')
            ->unique();

        if ($campaigns->isNotEmpty()) {
            throw ValidationException::withMessages(['content' => 'This content is part of campaigns that are not finished: '.$campaigns->implode(', ').'. Remove it from their sequence first.']);
        }

        // Soft delete: posting logs keep pointing at it; the stored file is kept for the same reason.
        $content->delete();
        $this->audit->log('content.deleted', $content, "Content \"{$content->title}\" deleted", [], $user);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $type = ContentType::from($data['type']);
        $source = $type === ContentType::Text ? ContentSource::None : ContentSource::from($data['source']);

        return [
            'title' => $data['title'],
            'type' => $type,
            'source' => $source,
            'text' => $type === ContentType::Text ? $data['text'] : null,
            'caption' => $type === ContentType::Text ? null : ($data['caption'] ?? null),
            'parse_mode' => $data['parse_mode'] ?? null ?: null,
            'media_url' => $source === ContentSource::Url ? trim((string) $data['media_url']) : null,
            'telegram_file_id' => $source === ContentSource::FileId ? trim((string) $data['telegram_file_id']) : null,
            'thumbnail' => $data['thumbnail'] ?? null ?: null,
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'disable_notification' => (bool) ($data['disable_notification'] ?? false),
            'disable_web_page_preview' => (bool) ($data['disable_web_page_preview'] ?? false),
            'protect_content' => (bool) ($data['protect_content'] ?? false),
        ] + ($source !== ContentSource::Upload ? ['media_path' => null, 'media_disk' => null, 'media_mime' => null, 'media_size' => null] : []);
    }

    /**
     * Uploads must be part of the account's plan and within its size limit.
     */
    private function assertUploadAllowed(?int $ownerId, ?UploadedFile $upload): void
    {
        if ($upload === null) {
            return;
        }

        $this->limiter->assertFeature($ownerId, 'allow_uploads', 'upload');
        $maxKb = $this->limiter->maxUploadKilobytes($ownerId);

        if ($maxKb !== null && $upload->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([
                'upload' => 'Your plan allows uploads up to '.round($maxKb / 1024).' MB. Use a smaller file, a media URL, or upgrade your plan.',
            ]);
        }
    }

    private function storeUpload(Content $content, UploadedFile $upload): void
    {
        $disk = $this->settings->mediaDisk();
        $extension = strtolower($upload->getClientOriginalExtension() ?: $upload->extension() ?: 'bin');
        $path = $upload->storeAs($this->directory(), Str::uuid().'.'.$extension, ['disk' => $disk]);

        if ($path === false) {
            throw ValidationException::withMessages(['upload' => 'The file could not be stored. Check the storage configuration.']);
        }

        $content->media_disk = $disk;
        $content->media_path = $path;
        $content->media_mime = $upload->getMimeType();
        $content->media_size = $upload->getSize();
        $content->metadata = array_merge($content->metadata ?? [], [
            'original_name' => Str::limit($upload->getClientOriginalName(), 200, ''),
        ]);
    }

    private function directory(): string
    {
        return 'contents/'.now()->format('Y/m');
    }
}
