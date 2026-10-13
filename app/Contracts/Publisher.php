<?php

namespace App\Contracts;

use App\Models\Content;
use App\Services\Telegram\TelegramApiException;
use App\Support\Publishing\PublishResult;
use App\Support\Publishing\PublishTarget;

/**
 * Delivers one piece of content to one destination on a messaging platform.
 *
 * Telegram is the only implementation today (TelegramPublisher). Other
 * platforms (Discord, WhatsApp, Facebook Pages, e-mail, ...) can be added by
 * implementing this interface and registering it in config/publishing.php;
 * PostingService, the queue jobs and the logging pipeline stay unchanged.
 */
interface Publisher
{
    /**
     * @throws TelegramApiException|\RuntimeException on delivery failure
     */
    public function publish(Content $content, PublishTarget $target): PublishResult;
}
