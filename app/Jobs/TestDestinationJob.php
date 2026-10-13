<?php

namespace App\Jobs;

use App\Models\TelegramDestination;
use App\Services\DestinationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Verifies that a destination's bot can reach and post in the chat, and
 * optionally sends a test message. The admin panel polls for the result.
 */
class TestDestinationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public int $uniqueFor = 120;

    public function __construct(
        public int $destinationId,
        public bool $sendMessage = true,
    ) {
        $this->onQueue(config('telegram.queues.telegram'));
    }

    public function uniqueId(): string
    {
        return (string) $this->destinationId;
    }

    public function handle(DestinationService $destinations): void
    {
        $destination = TelegramDestination::query()->with('bot')->find($this->destinationId);

        if ($destination) {
            $destinations->verify($destination, $this->sendMessage);
        }
    }
}
