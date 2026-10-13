<?php

namespace Tests\Unit;

use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramErrorType;
use App\Services\Telegram\TelegramService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TelegramErrorClassificationTest extends TestCase
{
    /**
     * @return array<string, array{int, string, TelegramErrorType, bool}>
     */
    public static function errors(): array
    {
        return [
            'invalid token' => [401, 'Unauthorized', TelegramErrorType::InvalidToken, false],
            'chat not found' => [400, 'Bad Request: chat not found', TelegramErrorType::ChatNotFound, false],
            'kicked' => [403, 'Forbidden: bot was kicked from the channel chat', TelegramErrorType::Forbidden, false],
            'not admin' => [400, 'Bad Request: not enough rights to send text messages to the chat', TelegramErrorType::MissingPermissions, false],
            'need admin' => [403, 'Forbidden: bot is not a member of the channel chat', TelegramErrorType::Forbidden, false],
            'bad url' => [400, 'Bad Request: failed to get HTTP URL content', TelegramErrorType::InvalidMedia, false],
            'bad file id' => [400, 'Bad Request: wrong file identifier/HTTP URL specified', TelegramErrorType::InvalidMedia, false],
            'flood' => [429, 'Too Many Requests: retry after 35', TelegramErrorType::FloodControl, true],
            'server' => [502, 'Bad Gateway', TelegramErrorType::ServerError, true],
            'bad request' => [400, 'Bad Request: message text is empty', TelegramErrorType::BadRequest, false],
        ];
    }

    #[Test]
    #[DataProvider('errors')]
    public function it_classifies_bot_api_errors(int $code, string $description, TelegramErrorType $type, bool $temporary): void
    {
        $e = TelegramApiException::fromResponse($code, ['ok' => false, 'error_code' => $code, 'description' => $description]);

        $this->assertSame($type, $e->type);
        $this->assertSame($temporary, $e->isTemporary());
        $this->assertNotEmpty($e->friendlyMessage());
    }

    #[Test]
    public function it_reads_retry_after_from_flood_control_responses(): void
    {
        $e = TelegramApiException::fromResponse(429, [
            'ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 35',
            'parameters' => ['retry_after' => 35],
        ]);

        $this->assertSame(35, $e->retryAfter);
        $this->assertStringContainsString('35 seconds', $e->friendlyMessage());
    }

    #[Test]
    public function connection_errors_are_temporary_and_redacted(): void
    {
        $e = TelegramApiException::connection('cURL error 28: Operation timed out for https://api.telegram.org/bot123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/sendMessage');

        $this->assertSame(TelegramErrorType::Timeout, $e->type);
        $this->assertTrue($e->isTemporary());
        $this->assertStringNotContainsString('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw', $e->getMessage());
    }

    #[Test]
    public function redact_removes_tokens_from_any_text(): void
    {
        $text = 'url=https://api.telegram.org/bot5555555:AAEyHk2dc9rvR6EjYzXyQbcZ8n3tEz5Y0aQ/getMe';

        $this->assertSame('url=https://api.telegram.org/bot[REDACTED]/getMe', TelegramService::redact($text));
    }
}
