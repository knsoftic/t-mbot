<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Media reference for a send* call: an existing file_id, a public URL that
 * Telegram downloads itself, or a file on one of our disks that is uploaded.
 */
final class TelegramFile
{
    private function __construct(
        public readonly string $kind,
        public readonly string $value,
        public readonly ?string $disk = null,
        public readonly ?string $filename = null,
    ) {}

    public static function fileId(string $fileId): self
    {
        return new self('file_id', trim($fileId));
    }

    public static function url(string $url): self
    {
        return new self('url', trim($url));
    }

    public static function storage(string $disk, string $path, ?string $filename = null): self
    {
        return new self('upload', $path, $disk, $filename ?? basename($path));
    }

    public function isUpload(): bool
    {
        return $this->kind === 'upload';
    }

    /**
     * Value to place in the request body for file_id / URL references.
     */
    public function reference(): string
    {
        if ($this->isUpload()) {
            throw new InvalidArgumentException('Uploads are sent as multipart attachments.');
        }

        return $this->value;
    }

    /**
     * @return resource
     */
    public function stream()
    {
        $stream = Storage::disk($this->disk)->readStream($this->value);

        if (! is_resource($stream)) {
            throw new TelegramApiException(TelegramErrorType::InvalidMedia, "Uploaded file is missing from storage ({$this->filename}).");
        }

        return $stream;
    }
}
