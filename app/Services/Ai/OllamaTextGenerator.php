<?php

namespace App\Services\Ai;

use App\Contracts\TextGenerator;
use App\Services\SettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to a local Ollama server (https://ollama.com) over its HTTP API.
 */
class OllamaTextGenerator implements TextGenerator
{
    public function __construct(private readonly SettingsService $settings) {}

    public function model(): string
    {
        return (string) $this->settings->get('ai.model', config('ai.model'));
    }

    public function generate(string $system, string $prompt, array $options = []): string
    {
        $base = config('ai.base_url');
        $model = $this->model();
        $timeout = (int) config('ai.timeout');
        $started = microtime(true);

        $payload = [
            'model' => $model,
            'stream' => false,
            // Hybrid "thinking" models (qwen3, deepseek-r1…) answer straight away instead
            // of spending the whole token budget on hidden reasoning.
            'think' => false,
            'keep_alive' => config('ai.keep_alive'),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $prompt],
            ],
            'options' => [
                'temperature' => $options['temperature'] ?? 0.7,
                'num_predict' => $options['max_tokens'] ?? (int) config('ai.max_tokens'),
                'num_ctx' => (int) config('ai.context_tokens'),
            ],
        ];

        $response = $this->chat($base, $payload, $timeout);

        // Ollama versions that do not know "think" for this model refuse it: ask again without it.
        if ($response->status() === 400 && str_contains(strtolower((string) $response->json('error')), 'think')) {
            unset($payload['think']);
            $response = $this->chat($base, $payload, $timeout);
        }

        if ($response->status() === 404 || str_contains(strtolower((string) $response->json('error')), 'not found')) {
            throw AiException::modelMissing($model);
        }

        if (! $response->successful()) {
            throw new AiException('The AI server returned an error: '.($response->json('error') ?: 'HTTP '.$response->status()));
        }

        $text = trim((string) $response->json('message.content'));

        Log::info('AI generation finished', [
            'model' => $model,
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'chars' => mb_strlen($text),
            'done_reason' => $response->json('done_reason'),
        ]);

        if ($text === '') {
            throw filled($response->json('message.thinking')) ? AiException::thinkingOnly($model) : AiException::emptyAnswer();
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function chat(string $base, array $payload, int $timeout): Response
    {
        try {
            return Http::acceptJson()
                ->connectTimeout(5)
                ->timeout($timeout)
                ->post("{$base}/api/chat", $payload);
        } catch (ConnectionException $e) {
            throw str_contains(strtolower($e->getMessage()), 'timed out') ? AiException::timeout($timeout) : AiException::unreachable($base);
        }
    }

    public function models(): array
    {
        $base = config('ai.base_url');

        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(5)->get("{$base}/api/tags");
        } catch (ConnectionException) {
            throw AiException::unreachable($base);
        }

        if (! $response->successful()) {
            throw AiException::unreachable($base);
        }

        return collect($response->json('models', []))->pluck('name')->filter()->values()->all();
    }
}
