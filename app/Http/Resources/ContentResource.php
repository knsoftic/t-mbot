<?php

namespace App\Http\Resources;

use App\Models\Content;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Content
 */
class ContentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type->value,
            'source' => $this->source->value,
            'text' => $this->text,
            'caption' => $this->caption,
            'parse_mode' => $this->parse_mode?->value,
            'media_url' => $this->media_url,
            'has_upload' => (bool) $this->media_path,
            'media_mime' => $this->media_mime,
            'media_size' => $this->media_size,
            'telegram_file_id' => $this->telegram_file_id,
            'thumbnail' => $this->thumbnail,
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('name')),
            'status' => $this->status->value,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'disable_notification' => $this->disable_notification,
            'disable_web_page_preview' => $this->disable_web_page_preview,
            'protect_content' => $this->protect_content,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
