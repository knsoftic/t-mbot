<?php

namespace App\Jobs;

/**
 * Animations / GIFs.
 */
class SendTelegramAnimationJob extends SendTelegramContentJob
{
    public int $timeout = 180;
}
