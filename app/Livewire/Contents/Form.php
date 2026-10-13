<?php

namespace App\Livewire\Contents;

use App\Enums\ContentSource;
use App\Enums\ContentType;
use App\Enums\ParseMode;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Content;
use App\Models\Tag;
use App\Services\ContentService;
use App\Services\MediaUrlValidator;
use App\Support\Permissions;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Create / edit a content item with a live Telegram-style preview.
 */
class Form extends Component
{
    use InteractsWithUi, WithFileUploads;

    public ?Content $content = null;

    public string $title = '';

    public string $type = 'text';

    public string $source = 'none';

    public string $text = '';

    public string $caption = '';

    public string $parse_mode = '';

    public string $media_url = '';

    public string $telegram_file_id = '';

    public string $thumbnail = '';

    public string $tagsInput = '';

    public string $status = 'active';

    public string $notes = '';

    public string $metadataJson = '';

    public bool $disable_notification = false;

    public bool $disable_web_page_preview = false;

    public bool $protect_content = false;

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    /** @var array{ok: bool, message: string}|null */
    public ?array $urlCheck = null;

    public function mount(?Content $content = null): void
    {
        $this->authorize(Permissions::CONTENTS_MANAGE);

        if ($content?->exists) {
            $this->content = $content;
            $this->fill([
                'title' => $content->title,
                'type' => $content->type->value,
                'source' => $content->source->value,
                'text' => (string) $content->text,
                'caption' => (string) $content->caption,
                'parse_mode' => (string) $content->parse_mode?->value,
                'media_url' => (string) $content->media_url,
                'telegram_file_id' => (string) $content->telegram_file_id,
                'thumbnail' => (string) $content->thumbnail,
                'tagsInput' => $content->tags()->pluck('name')->implode(', '),
                'status' => $content->status->value,
                'notes' => (string) $content->notes,
                'metadataJson' => $content->metadata ? json_encode(collect($content->metadata)->except('original_name')->all() ?: null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
                'disable_notification' => $content->disable_notification,
                'disable_web_page_preview' => $content->disable_web_page_preview,
                'protect_content' => $content->protect_content,
            ]);

            if ($this->metadataJson === 'null') {
                $this->metadataJson = '';
            }
        }
    }

    public function updatedType(string $value): void
    {
        $type = ContentType::tryFrom($value);

        if ($type === ContentType::Text) {
            $this->source = ContentSource::None->value;
        } elseif ($this->source === ContentSource::None->value) {
            $this->source = ContentSource::Url->value;
        }

        $this->upload = null;
        $this->resetValidation(['upload', 'source']);
    }

    public function updatedMediaUrl(): void
    {
        $this->urlCheck = null;
    }

    public function updatedUpload(): void
    {
        $type = ContentType::tryFrom($this->type);

        if ($type && $type->isMedia()) {
            $this->validateOnly('upload', ['upload' => ['file', 'extensions:'.implode(',', $type->allowedExtensions()), 'max:'.$type->maxUploadKilobytes()]]);
        }
    }

    public function checkUrl(MediaUrlValidator $validator): void
    {
        if (RateLimiter::tooManyAttempts('url-check:'.auth()->id(), 20)) {
            $this->urlCheck = ['ok' => false, 'message' => 'Too many checks. Wait a minute.'];

            return;
        }

        RateLimiter::hit('url-check:'.auth()->id());
        $result = $validator->probe(trim($this->media_url));
        $this->urlCheck = ['ok' => $result['ok'], 'message' => $result['message']];
    }

    public function save(bool $createAnother = false): void
    {
        $this->authorize(Permissions::CONTENTS_MANAGE);
        $contents = app(ContentService::class);

        $hasStoredUpload = (bool) $this->content?->media_path && $this->content->source === ContentSource::Upload;
        $metadata = null;

        if (trim($this->metadataJson) !== '') {
            $metadata = json_decode($this->metadataJson, true);

            if (! is_array($metadata)) {
                $this->addError('metadataJson', 'Metadata must be a valid JSON object.');

                return;
            }
        }

        // Tags and metadata arrive as text inputs here and are parsed below.
        $rules = collect($contents->rules($this->type, $this->source, $hasStoredUpload))->except(['tags', 'tags.*', 'metadata'])->all();

        $data = $this->validate(
            $rules + ['tagsInput' => ['nullable', 'string', 'max:500']],
            [],
            ['media_url' => 'media URL', 'telegram_file_id' => 'Telegram file ID', 'parse_mode' => 'formatting'],
        );

        $data['tags'] = collect(explode(',', $this->tagsInput))->map(fn ($t) => trim($t))->filter()->unique()->values()->all();
        $data['metadata'] = $metadata;
        $data['parse_mode'] = $this->parse_mode ?: null;

        $saved = null;
        $ok = $this->attempt(function () use ($contents, $data, &$saved) {
            $saved = $this->content
                ? $contents->update($this->content, $data, $this->upload, auth()->user())
                : $contents->create($data, $this->upload, auth()->user());
        });

        if (! $ok) {
            return;
        }

        $this->flash("“{$saved->title}” saved.");

        if ($createAnother) {
            $this->redirectRoute('contents.create', navigate: true);

            return;
        }

        $this->redirectRoute('contents.index', navigate: true);
    }

    /**
     * Unsaved model mirroring the form, for the live preview.
     */
    private function previewModel(): Content
    {
        $preview = new Content([
            'title' => $this->title,
            'type' => ContentType::tryFrom($this->type) ?? ContentType::Text,
            'source' => ContentSource::tryFrom($this->source) ?? ContentSource::None,
            'text' => $this->text,
            'caption' => $this->caption,
            'parse_mode' => ParseMode::tryFrom($this->parse_mode),
            'media_url' => $this->media_url,
            'telegram_file_id' => $this->telegram_file_id,
        ]);

        if ($this->source === ContentSource::Upload->value && $this->content?->media_path && ! $this->upload) {
            $preview->id = $this->content->id;
            $preview->exists = true;
            $preview->media_path = $this->content->media_path;
            $preview->media_size = $this->content->media_size;
            $preview->metadata = $this->content->metadata;
        }

        return $preview;
    }

    public function render()
    {
        $type = ContentType::tryFrom($this->type) ?? ContentType::Text;
        $uploadPreviewUrl = null;

        if ($this->upload && method_exists($this->upload, 'isPreviewable') && $this->upload->isPreviewable()) {
            try {
                $uploadPreviewUrl = $this->upload->temporaryUrl();
            } catch (\Throwable) {
                $uploadPreviewUrl = null;
            }
        }

        $preview = $this->previewModel();

        // Show a freshly picked (not yet saved) file through Livewire's signed temporary URL.
        if ($uploadPreviewUrl && $this->source === ContentSource::Upload->value) {
            $preview->source = ContentSource::Url;
            $preview->media_url = $uploadPreviewUrl;
        }

        return view('livewire.contents.form', [
            'typeEnum' => $type,
            'preview' => $preview,
            'uploadPreviewUrl' => $uploadPreviewUrl,
            'knownTags' => Tag::query()->orderBy('name')->limit(200)->pluck('name'),
            'bodyLength' => mb_strlen($type === ContentType::Text ? $this->text : $this->caption),
            'bodyLimit' => $type === ContentType::Text ? 4096 : 1024,
        ])->title($this->content ? 'Edit content' : 'New content');
    }
}
