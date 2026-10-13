<?php

namespace App\Enums;

enum ContentSource: string
{
    use Concerns;

    case None = 'none';
    case Url = 'url';
    case Upload = 'upload';
    case FileId = 'file_id';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Text only',
            self::Url => 'External URL',
            self::Upload => 'Uploaded file',
            self::FileId => 'Telegram file ID',
        };
    }
}
