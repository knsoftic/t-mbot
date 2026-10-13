<?php

namespace App\Jobs;

/**
 * Text messages are small and quick.
 */
class SendTelegramMessageJob extends SendTelegramContentJob
{
    public int $timeout = 60;
}
