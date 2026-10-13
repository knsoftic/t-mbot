<?php

namespace App\Logging;

use App\Services\Telegram\TelegramService;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Monolog tap that strips bot tokens and obvious secrets from every record.
 */
class RedactSecrets
{
    private const SECRET_KEYS = ['token', 'password', 'password_confirmation', 'secret', 'api_key', 'authorization'];

    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->pushProcessor(function (LogRecord $record): LogRecord {
                return $record->with(
                    message: TelegramService::redact($record->message),
                    context: $this->scrub($record->context),
                    extra: $this->scrub($record->extra),
                );
            });
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_string($value)) {
                $data[$key] = TelegramService::redact($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->scrub($value);
            } elseif ($value instanceof \Throwable && TelegramService::redact($value->getMessage()) !== $value->getMessage()) {
                // Only exceptions whose message carries a token lose their trace.
                $data[$key] = get_class($value).': '.TelegramService::redact($value->getMessage())
                    .' in '.$value->getFile().':'.$value->getLine();
            }
        }

        return $data;
    }
}
