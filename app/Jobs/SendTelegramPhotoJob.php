<?php

namespace App\Jobs;

/**
 * Photos (sent by file_id, URL or upload).
 */
class SendTelegramPhotoJob extends SendTelegramContentJob
{
    public int $timeout = 120;
}
