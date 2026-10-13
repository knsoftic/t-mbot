<?php

namespace App\Services\Telegram;

use App\Contracts\Publisher;
use App\Enums\ContentSource;
use App\Enums\ContentType;
use App\Models\Content;
use App\Models\ContentBotFile;
use App\Models\TelegramBot;
use App\Services\MediaUrlValidator;
use App\Support\Publishing\PublishResult;
use App\Support\Publishing\PublishTarget;

/**
 * Turns a Content record into the right Bot API call.
 *
 * Media source preference: a file_id this bot already received for the
 * content (no re-download, no re-upload) > the admin supplied file_id > the
 * external URL (Telegram downloads it) > uploading the stored file. After a
 * successful media send the returned file_id is cached per bot.
 */
class TelegramPublisher implements Publisher
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly MediaUrlValidator $urlValidator,
    ) {}

    public function publish(Content $content, PublishTarget $target): PublishResult
    {
        $bot = $target->account;
        $client = $this->telegram->forBot($bot);
        $started = microtime(true);

        if ($content->type === ContentType::Text) {
            $message = $client->sendMessage($target->address, (string) $content->text, $this->textOptions($content));

            return $this->result($message, $started, 'none');
        }

        [$file, $source] = $this->resolveMedia($content, $bot);

        try {
            $message = $this->sendMedia($client, $content, $target->address, $file);
        } catch (TelegramApiException $e) {
            // A cached file_id can become invalid; drop it and fall back to the original media once.
            if ($source !== 'cache' || $e->type !== TelegramErrorType::InvalidMedia) {
                throw $e;
            }

            ContentBotFile::query()->where('content_id', $content->id)->where('bot_id', $bot->id)->delete();
            [$file, $source] = $this->resolveMedia($content, $bot, useCache: false);
            $message = $this->sendMedia($client, $content, $target->address, $file);
        }

        $this->rememberFileId($content, $bot, $message);

        return $this->result($message, $started, $source);
    }

    /**
     * Build the Bot API parameters for a text post (also used by previews/tests).
     *
     * @return array<string, mixed>
     */
    public function textOptions(Content $content): array
    {
        return array_filter([
            'parse_mode' => $content->parse_mode?->value,
            'link_preview_options' => $content->disable_web_page_preview ? ['is_disabled' => true] : null,
            'disable_notification' => $content->disable_notification ?: null,
            'protect_content' => $content->protect_content ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function mediaOptions(Content $content): array
    {
        return array_filter([
            'caption' => $content->caption !== null && $content->caption !== '' ? $content->caption : null,
            'parse_mode' => $content->caption ? $content->parse_mode?->value : null,
            'supports_streaming' => $content->type === ContentType::Video ? true : null,
            'disable_notification' => $content->disable_notification ?: null,
            'protect_content' => $content->protect_content ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @return array{0: TelegramFile, 1: string}
     */
    public function resolveMedia(Content $content, TelegramBot $bot, bool $useCache = true): array
    {
        if ($useCache) {
            $cached = ContentBotFile::query()->where('content_id', $content->id)->where('bot_id', $bot->id)->value('file_id');

            if ($cached) {
                return [TelegramFile::fileId($cached), 'cache'];
            }
        }

        return match ($content->source) {
            ContentSource::FileId => $content->telegram_file_id
                ? [TelegramFile::fileId($content->telegram_file_id), 'file_id']
                : throw new TelegramApiException(TelegramErrorType::InvalidMedia, 'No Telegram file ID set on this content.'),
            ContentSource::Url => $this->urlFile($content),
            ContentSource::Upload => $content->media_path
                ? [TelegramFile::storage($content->media_disk ?: 'local', $content->media_path, $content->metadata['original_name'] ?? null), 'upload']
                : throw new TelegramApiException(TelegramErrorType::InvalidMedia, 'No uploaded file is attached to this content.'),
            default => throw new TelegramApiException(TelegramErrorType::InvalidMedia, 'This media content has no media source.'),
        };
    }

    /**
     * @return array{0: TelegramFile, 1: string}
     */
    private function urlFile(Content $content): array
    {
        if ($error = $this->urlValidator->validate($content->media_url)) {
            throw new TelegramApiException(TelegramErrorType::InvalidMedia, $error);
        }

        return [TelegramFile::url((string) $content->media_url), 'url'];
    }

    /**
     * @return array<string, mixed>
     */
    private function sendMedia(TelegramService $client, Content $content, string $chatId, TelegramFile $file): array
    {
        $options = $this->mediaOptions($content);

        return match ($content->type) {
            ContentType::Image => $client->sendPhoto($chatId, $file, $options),
            ContentType::Video => $client->sendVideo($chatId, $file, $options),
            ContentType::Document => $client->sendDocument($chatId, $file, $options),
            ContentType::Audio => $client->sendAudio($chatId, $file, $options),
            ContentType::Animation => $client->sendAnimation($chatId, $file, $options),
            ContentType::Text => throw new \LogicException('Text content has no media.'),
        };
    }

    /**
     * Extract the file_id Telegram assigned to the media we just sent.
     *
     * @param  array<string, mixed>  $message
     * @return array{file_id: string, file_unique_id: ?string}|null
     */
    public static function extractFile(ContentType $type, array $message): ?array
    {
        $media = match ($type) {
            // Photos come back as an array of sizes; the last one is the largest.
            ContentType::Image => is_array($message['photo'] ?? null) ? end($message['photo']) : null,
            ContentType::Video => $message['video'] ?? $message['document'] ?? null,
            ContentType::Document => $message['document'] ?? null,
            ContentType::Audio => $message['audio'] ?? $message['voice'] ?? $message['document'] ?? null,
            ContentType::Animation => $message['animation'] ?? $message['document'] ?? null,
            ContentType::Text => null,
        };

        if (! is_array($media) || empty($media['file_id'])) {
            return null;
        }

        return ['file_id' => (string) $media['file_id'], 'file_unique_id' => $media['file_unique_id'] ?? null];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function rememberFileId(Content $content, TelegramBot $bot, array $message): void
    {
        if (! $file = self::extractFile($content->type, $message)) {
            return;
        }

        ContentBotFile::query()->updateOrCreate(
            ['content_id' => $content->id, 'bot_id' => $bot->id],
            ['file_id' => $file['file_id'], 'file_unique_id' => $file['file_unique_id']],
        );
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function result(array $message, float $started, string $source): PublishResult
    {
        return new PublishResult(
            messageId: (string) ($message['message_id'] ?? ''),
            response: TelegramService::sanitizeResponse($message) ?? [],
            durationMs: (int) round((microtime(true) - $started) * 1000),
            mediaSource: $source,
        );
    }
}
