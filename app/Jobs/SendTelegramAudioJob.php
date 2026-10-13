<?php

namespace App\Jobs;

/**
 * Audio files.
 */
class SendTelegramAudioJob extends SendTelegramContentJob
{
    public int $timeout = 300;
}
