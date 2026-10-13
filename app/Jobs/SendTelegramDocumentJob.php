<?php

namespace App\Jobs;

/**
 * Documents may be large uploads.
 */
class SendTelegramDocumentJob extends SendTelegramContentJob
{
    public int $timeout = 300;
}
