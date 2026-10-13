<?php

namespace App\Services\Telegram;

use RuntimeException;
use Throwable;

/**
 * A failed Bot API call. Messages never contain the bot token.
 */
class TelegramApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $response  Decoded API response (safe to store).
     */
    public function __construct(
        public readonly TelegramErrorType $type,
        string $description,
        public readonly ?int $errorCode = null,
        public readonly ?int $retryAfter = null,
        public readonly ?array $response = null,
        public readonly ?int $migrateToChatId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($description, (int) $errorCode, $previous);
    }

    /**
     * Build from an unsuccessful Bot API response.
     *
     * @param  array<string, mixed>|null  $body
     */
    public static function fromResponse(int $httpStatus, ?array $body): self
    {
        $code = (int) ($body['error_code'] ?? $httpStatus);
        $description = (string) ($body['description'] ?? "HTTP {$httpStatus}");
        $parameters = (array) ($body['parameters'] ?? []);

        return new self(
            type: self::classify($code, $description),
            description: $description,
            errorCode: $code,
            retryAfter: isset($parameters['retry_after']) ? (int) $parameters['retry_after'] : null,
            response: $body,
            migrateToChatId: isset($parameters['migrate_to_chat_id']) ? (int) $parameters['migrate_to_chat_id'] : null,
        );
    }

    public static function connection(string $message, ?Throwable $previous = null): self
    {
        $isTimeout = (bool) preg_match('/timed? ?out|cURL error 28/i', $message);

        return new self(
            type: $isTimeout ? TelegramErrorType::Timeout : TelegramErrorType::Network,
            description: TelegramService::redact($message),
            previous: null,
        );
    }

    public static function classify(int $code, string $description): TelegramErrorType
    {
        $d = strtolower($description);

        return match (true) {
            $code === 429 => TelegramErrorType::FloodControl,
            $code === 401, $code === 404 && str_contains($d, 'not found') && ! str_contains($d, 'chat') => TelegramErrorType::InvalidToken,
            str_contains($d, 'chat not found'), str_contains($d, 'chat_id is empty'), str_contains($d, 'peer_id_invalid') => TelegramErrorType::ChatNotFound,
            str_contains($d, 'not enough rights'), str_contains($d, 'have no rights'), str_contains($d, 'need administrator rights'),
            str_contains($d, 'chat_write_forbidden'), str_contains($d, 'chat_admin_required') => TelegramErrorType::MissingPermissions,
            $code === 403 => TelegramErrorType::Forbidden,
            str_contains($d, 'wrong file identifier'), str_contains($d, 'wrong remote file'), str_contains($d, 'failed to get http url content'),
            str_contains($d, 'wrong type of the web page content'), str_contains($d, 'file is too big'), str_contains($d, 'photo_invalid'),
            str_contains($d, 'image_process_failed'), str_contains($d, 'wrong padding'), str_contains($d, 'file must be non-empty'),
            str_contains($d, 'webpage_curl_failed'), str_contains($d, 'webpage_media_empty'), str_contains($d, 'invalid file http url') => TelegramErrorType::InvalidMedia,
            $code >= 500 => TelegramErrorType::ServerError,
            $code === 400 => TelegramErrorType::BadRequest,
            default => TelegramErrorType::Unknown,
        };
    }

    public function isTemporary(): bool
    {
        return $this->type->isTemporary();
    }

    /**
     * Message suitable for administrators (no stack traces or secrets).
     */
    public function friendlyMessage(): string
    {
        $detail = $this->getMessage();

        return match ($this->type) {
            TelegramErrorType::InvalidToken => 'The bot token is invalid or was revoked. Generate a new token with @BotFather and update the bot.',
            TelegramErrorType::ChatNotFound => 'Chat not found. Check the chat ID / @username and make sure the bot has been added to the chat.',
            TelegramErrorType::Forbidden => "The bot is not allowed to post in this chat ({$detail}). Add the bot to the chat and, for channels, make it an administrator.",
            TelegramErrorType::MissingPermissions => "The bot lacks permissions ({$detail}). For channels the bot must be an administrator with the \"Post messages\" right.",
            TelegramErrorType::InvalidMedia => "Telegram could not use the media ({$detail}). Check that the URL is public, points directly to the file, and is within Telegram's size limits.",
            TelegramErrorType::FloodControl => 'Telegram rate limit reached. Retrying after '.($this->retryAfter ?? '?').' seconds.',
            TelegramErrorType::Timeout => 'Telegram did not respond in time. The request will be retried.',
            TelegramErrorType::Network => 'Could not reach the Telegram API (network error). The request will be retried.',
            TelegramErrorType::ServerError => "Telegram returned a server error ({$detail}). The request will be retried.",
            TelegramErrorType::BadRequest => "Telegram rejected the request: {$detail}",
            TelegramErrorType::Unknown => "Unexpected Telegram error: {$detail}",
        };
    }
}
