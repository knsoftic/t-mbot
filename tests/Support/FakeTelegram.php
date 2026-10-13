<?php

namespace Tests\Support;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

/**
 * In-memory stand-in for the Bot API used by the integration tests.
 *
 * Responses follow the documented Bot API shapes. Per-method overrides can
 * be queued with failNext() to simulate errors and flood control.
 */
class FakeTelegram
{
    /** @var list<array{method: string, token: string, params: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>}>> */
    private array $queued = [];

    private int $messageId = 1000;

    public static function install(): self
    {
        $fake = new self;

        Http::fake([
            'api.telegram.org/*' => fn (Request $request) => $fake->handle($request),
        ]);

        return $fake;
    }

    /**
     * Make the next call(s) to $method fail with a Bot API error.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function failNext(string $method, int $code, string $description, array $parameters = [], int $times = 1): self
    {
        for ($i = 0; $i < $times; $i++) {
            $this->queued[$method][] = [
                'status' => $code,
                'body' => array_filter(['ok' => false, 'error_code' => $code, 'description' => $description, 'parameters' => $parameters ?: null], fn ($v) => $v !== null),
            ];
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function respondNext(string $method, array $result): self
    {
        $this->queued[$method][] = ['status' => 200, 'body' => ['ok' => true, 'result' => $result]];

        return $this;
    }

    private function handle(Request $request): PromiseInterface
    {
        preg_match('#/bot([^/]+)/(\w+)$#', $request->url(), $m);
        [$token, $method] = [$m[1] ?? '', $m[2] ?? ''];
        $params = $request->isMultipart()
            ? collect($request->data())->mapWithKeys(fn ($part) => [$part['name'] => is_string($part['contents']) ? $part['contents'] : '[file]'])->all()
            : $request->data();

        $this->calls[] = compact('method', 'token', 'params');

        if (! empty($this->queued[$method])) {
            $next = array_shift($this->queued[$method]);

            return Http::response($next['body'], $next['status']);
        }

        return Http::response(['ok' => true, 'result' => $this->result($method, $params)]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function result(string $method, array $params): array
    {
        $chat = ['id' => is_numeric($params['chat_id'] ?? null) ? (int) $params['chat_id'] : -1001000000001, 'type' => 'channel', 'title' => 'Fake channel'];
        $message = ['message_id' => ++$this->messageId, 'date' => time(), 'chat' => $chat];
        $file = fn (string $prefix) => ['file_id' => $prefix.'-file-'.$this->messageId, 'file_unique_id' => 'u'.$this->messageId];

        return match ($method) {
            'getMe' => ['id' => 7000001, 'is_bot' => true, 'first_name' => 'Fake', 'username' => 'fake_test_bot'],
            'getChat' => $chat,
            'getChatMember' => ['status' => 'administrator', 'can_post_messages' => true, 'user' => ['id' => 7000001]],
            'sendMessage' => $message + ['text' => $params['text'] ?? ''],
            'sendPhoto' => $message + ['photo' => [$file('small'), $file('photo')]],
            'sendVideo' => $message + ['video' => $file('video')],
            'sendDocument' => $message + ['document' => $file('doc')],
            'sendAudio' => $message + ['audio' => $file('audio')],
            'sendAnimation' => $message + ['animation' => $file('anim')],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return array_column($this->calls, 'method');
    }

    /**
     * @return list<array{method: string, token: string, params: array<string, mixed>}>
     */
    public function sends(): array
    {
        return array_values(array_filter($this->calls, fn ($c) => str_starts_with($c['method'], 'send')));
    }

    public function assertSentCount(int $expected): void
    {
        Assert::assertCount($expected, $this->sends(), 'Unexpected number of send* calls: '.implode(', ', array_column($this->sends(), 'method')));
    }

    public function lastSend(): ?array
    {
        $sends = $this->sends();

        return $sends === [] ? null : end($sends);
    }

    public function each(Closure $callback): void
    {
        array_map($callback, $this->calls);
    }
}
