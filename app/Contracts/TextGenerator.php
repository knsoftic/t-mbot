<?php

namespace App\Contracts;

use App\Services\Ai\AiException;

/**
 * A text-generation model. The local Ollama server is the only
 * implementation today; another local runtime (llama.cpp, vLLM) or a hosted
 * API can be added by implementing this interface and binding it in
 * AppServiceProvider.
 */
interface TextGenerator
{
    /**
     * @param  array{temperature?: float, max_tokens?: int}  $options
     *
     * @throws AiException
     */
    public function generate(string $system, string $prompt, array $options = []): string;

    /**
     * Names of the models available on the server.
     *
     * @return list<string>
     *
     * @throws AiException
     */
    public function models(): array;
}
