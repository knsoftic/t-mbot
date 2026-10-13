<?php

namespace App\Support\Publishing;

final class PublishResult
{
    /**
     * @param  array<string, mixed>  $response  Sanitised platform response.
     */
    public function __construct(
        public readonly string $messageId,
        public readonly array $response,
        public readonly int $durationMs,
        public readonly string $mediaSource = 'none',
    ) {}
}
