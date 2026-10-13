<?php

namespace App\Jobs;

/**
 * Videos may be large uploads.
 */
class SendTelegramVideoJob extends SendTelegramContentJob
{
    public int $timeout = 300;
}
