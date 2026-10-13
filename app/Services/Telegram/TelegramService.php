<?php

namespace App\Services\Telegram;

use App\Models\TelegramBot;
use App\Services\SettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The only class that talks to the Telegram Bot API.
 *
 * Usage: app(TelegramService::class)->forBot($bot)->sendMessage($chatId, 'Hi');
 *
 * The token is only ever placed in the request URL; every error message that
 * leaves this class is passed through redact() first.
 */
class TelegramService
{
    private ?string $token = null;

    public function __construct(private readonly SettingsService $settings) {}

    public function forBot(TelegramBot $bot): static
    {
        return $this->withToken((string) $bot->token);
    }

    public function withToken(string $token): static
    {
        $clone = clone $this;
        $clone->token = trim($token);

        return $clone;
    }

    /**
     * @return array<string, mixed> Bot user object.
     */
    public function getMe(): array
    {
        return $this->call('getMe');
    }

    /**
     * @return array<string, mixed> Chat object.
     */
    public function getChat(string|int $chatId): array
    {
        return $this->call('getChat', ['chat_id' => $chatId]);
    }

    /**
     * @return array<string, mixed> ChatMember object.
     */
    public function getChatMember(string|int $chatId, int $userId): array
    {
        return $this->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> Message object.
     */
    public function sendMessage(string|int $chatId, string $text, array $options = []): array
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text] + $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function sendPhoto(string|int $chatId, TelegramFile $photo, array $options = []): array
    {
        return $this->sendMedia('sendPhoto', 'photo', $chatId, $photo, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function sendVideo(string|int $chatId, TelegramFile $video, array $options = []): array
    {
        return $this->sendMedia('sendVideo', 'video', $chatId, $video, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function sendDocument(string|int $chatId, TelegramFile $document, array $options = []): array
    {
        return $this->sendMedia('sendDocument', 'document', $chatId, $document, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function sendAudio(string|int $chatId, TelegramFile $audio, array $options = []): array
    {
        return $this->sendMedia('sendAudio', 'audio', $chatId, $audio, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function sendAnimation(string|int $chatId, TelegramFile $animation, array $options = []): array
    {
        return $this->sendMedia('sendAnimation', 'animation', $chatId, $animation, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function sendMedia(string $method, string $field, string|int $chatId, TelegramFile $file, array $options = []): array
    {
        $params = ['chat_id' => $chatId] + $options;

        if ($file->isUpload()) {
            return $this->call($method, $params, [$field => $file]);
        }

        return $this->call($method, $params + [$field => $file->reference()]);
    }

    /**
     * Perform a Bot API call and return its "result".
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, TelegramFile>  $uploads
     * @return array<string, mixed>
     *
     * @throws TelegramApiException
     */
    public function call(string $method, array $params = [], array $uploads = []): array
    {
        if ($this->token === null || $this->token === '') {
            throw new LogicException('No bot token configured; call forBot() first.');
        }

        $url = config('telegram.api_url').'/bot'.$this->token.'/'.$method;
        $started = microtime(true);

        try {
            $request = $this->request();

            // Telegram downloads URL media itself and uploads can be large: allow more time.
            if ($uploads !== []) {
                $request = $request->timeout(max($this->timeout(), 240));
            } elseif (str_starts_with($method, 'send') && $method !== 'sendMessage') {
                $request = $request->timeout(max($this->timeout(), 90));
            }

            if ($uploads !== []) {
                foreach ($uploads as $field => $file) {
                    $request = $request->attach($field, $file->stream(), $file->filename);
                }

                $response = $request->post($url, $this->flattenForMultipart($params));
            } else {
                $response = $request->asJson()->post($url, $params);
            }
        } catch (ConnectionException $e) {
            $exception = TelegramApiException::connection($e->getMessage());
            $this->logFailure($method, $exception, $started);

            throw $exception;
        }

        $body = $response->json();

        if (! $response->successful() || ! is_array($body) || ($body['ok'] ?? false) !== true) {
            $exception = TelegramApiException::fromResponse($response->status(), is_array($body) ? $body : null);
            $this->logFailure($method, $exception, $started);

            throw $exception;
        }

        Log::channel('telegram')->debug('Telegram API call succeeded', [
            'method' => $method,
            'chat_id' => $params['chat_id'] ?? null,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return is_array($body['result'] ?? null) ? $body['result'] : ['value' => $body['result'] ?? null];
    }

    /**
     * Remove bot tokens from any string (URLs in exception messages, etc.).
     */
    public static function redact(string $text): string
    {
        return (string) preg_replace('/\d{5,}:[A-Za-z0-9_-]{20,}/', '[REDACTED]', $text);
    }

    protected function request(): PendingRequest
    {
        // 4xx/5xx responses are not thrown by the HTTP client; call() classifies them.
        return Http::acceptJson()
            ->timeout($this->timeout())
            ->connectTimeout((int) config('telegram.connect_timeout'));
    }

    protected function timeout(): int
    {
        return max(5, (int) $this->settings->get('telegram.api_timeout', config('telegram.timeout')));
    }

    /**
     * Multipart bodies cannot carry nested arrays: JSON-encode them.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function flattenForMultipart(array $params): array
    {
        return array_map(
            fn ($value) => match (true) {
                is_array($value) => json_encode($value),
                is_bool($value) => $value ? 'true' : 'false',
                default => $value,
            },
            $params,
        );
    }

    protected function logFailure(string $method, TelegramApiException $e, float $started): void
    {
        Log::channel('telegram')->warning('Telegram API call failed', [
            'method' => $method,
            'type' => $e->type->value,
            'error_code' => $e->errorCode,
            'description' => $e->getMessage(),
            'retry_after' => $e->retryAfter,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    /**
     * Response bodies are stored for diagnostics; make sure they never carry a token.
     *
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    public static function sanitizeResponse(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        return json_decode(self::redact((string) json_encode($data)), true);
    }
}
