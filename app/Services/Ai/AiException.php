<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * A failed AI call, with a message that can be shown to administrators.
 */
class AiException extends RuntimeException
{
    public static function unreachable(string $baseUrl): self
    {
        return new self("The local AI server is not reachable at {$baseUrl}. Is Ollama installed and running? (systemctl status ollama)");
    }

    public static function modelMissing(string $model): self
    {
        return new self("The model \"{$model}\" is not installed on the server. Install it with: ollama pull {$model}");
    }

    public static function timeout(int $seconds): self
    {
        return new self("The AI did not finish within {$seconds} seconds. Try a shorter length or a smaller model.");
    }

    public static function disabled(): self
    {
        return new self('AI is turned off. Enable it in Settings → AI.');
    }

    public static function thinkingOnly(string $model): self
    {
        return new self("\"{$model}\" is a reasoning (thinking-only) model and used its whole answer for thinking. Use a normal chat model such as qwen2.5:1.5b.");
    }

    public static function reasoningLeak(string $model): self
    {
        return new self("\"{$model}\" wrote its reasoning instead of a post (it is a thinking model). Use a normal chat model such as qwen2.5:1.5b.");
    }

    public static function emptyAnswer(): self
    {
        return new self('The AI returned an empty answer. Try again or rephrase the topic.');
    }
}
