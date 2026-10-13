<?php

namespace App\Enums;

enum ContentType: string
{
    use Concerns;

    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
    case Document = 'document';
    case Audio = 'audio';
    case Animation = 'animation';

    public function label(): string
    {
        return $this === self::Animation ? 'Animation / GIF' : ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Text => 'slate',
            self::Image => 'green',
            self::Video => 'purple',
            self::Document => 'amber',
            self::Audio => 'pink',
            self::Animation => 'cyan',
        };
    }

    public function isMedia(): bool
    {
        return $this !== self::Text;
    }

    /**
     * Bot API method used to deliver this type.
     */
    public function telegramMethod(): string
    {
        return match ($this) {
            self::Text => 'sendMessage',
            self::Image => 'sendPhoto',
            self::Video => 'sendVideo',
            self::Document => 'sendDocument',
            self::Audio => 'sendAudio',
            self::Animation => 'sendAnimation',
        };
    }

    /**
     * Name of the media parameter for the Bot API method.
     */
    public function telegramField(): ?string
    {
        return match ($this) {
            self::Text => null,
            self::Image => 'photo',
            self::Video => 'video',
            self::Document => 'document',
            self::Audio => 'audio',
            self::Animation => 'animation',
        };
    }

    /**
     * Allowed upload extensions.
     *
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        return match ($this) {
            self::Text => [],
            self::Image => ['jpg', 'jpeg', 'png', 'webp'],
            self::Video => ['mp4', 'mov', 'm4v', 'webm'],
            self::Document => ['pdf', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rar', '7z', 'epub'],
            self::Audio => ['mp3', 'm4a', 'ogg', 'wav', 'flac', 'aac'],
            self::Animation => ['gif', 'mp4'],
        };
    }

    /**
     * Maximum upload size in kilobytes (Bot API: 10 MB photos, 50 MB other files).
     */
    public function maxUploadKilobytes(): int
    {
        return $this === self::Image ? 10 * 1024 : 50 * 1024;
    }
}
