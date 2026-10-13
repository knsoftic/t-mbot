<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bot API endpoint
    |--------------------------------------------------------------------------
    |
    | Override this when running a self-hosted Telegram Bot API server.
    |
    */

    'api_url' => rtrim(env('TELEGRAM_API_URL', 'https://api.telegram.org'), '/'),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeouts (seconds)
    |--------------------------------------------------------------------------
    |
    | The request timeout can also be changed at runtime from System Settings.
    |
    */

    'timeout' => (int) env('TELEGRAM_TIMEOUT', 30),

    'connect_timeout' => (int) env('TELEGRAM_CONNECT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | "campaigns" runs the lightweight orchestration jobs, "telegram" runs the
    | jobs that call the Bot API. Workers must listen to both (see README).
    |
    */

    'queues' => [
        'campaigns' => env('TELEGRAM_CAMPAIGN_QUEUE', 'campaigns'),
        'telegram' => env('TELEGRAM_SEND_QUEUE', 'telegram'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recovery
    |--------------------------------------------------------------------------
    |
    | An item that has been "processing" longer than this is considered
    | interrupted (worker crash, server restart) and is recovered.
    |
    */

    'stale_processing_minutes' => (int) env('TELEGRAM_STALE_PROCESSING_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Retry / backoff limits
    |--------------------------------------------------------------------------
    */

    'max_backoff_seconds' => 3600,

    'max_flood_waits' => 20,

];
