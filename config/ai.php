<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Local AI (offline LLM)
    |--------------------------------------------------------------------------
    |
    | The platform talks to a local model server (Ollama) running on the same
    | machine. Nothing is sent to an external AI service. The endpoint is only
    | configurable here (not in the admin panel) so it cannot be pointed at
    | arbitrary hosts. Turning the feature on/off and choosing the model is
    | done in Settings → AI.
    |
    */

    'driver' => env('AI_DRIVER', 'ollama'),

    'base_url' => rtrim(env('AI_BASE_URL', 'http://127.0.0.1:11434'), '/'),

    // Default model; can be overridden in Settings → AI.
    'model' => env('AI_MODEL', 'qwen2.5:1.5b'),

    // Seconds to wait for one generation (small models on 2 CPU cores are slow).
    'timeout' => (int) env('AI_TIMEOUT', 180),

    // How long Ollama keeps the model in RAM after the last request.
    'keep_alive' => env('AI_KEEP_ALIVE', '5m'),

    'max_tokens' => (int) env('AI_MAX_TOKENS', 400),

    'context_tokens' => (int) env('AI_CONTEXT_TOKENS', 2048),

];
