<?php

namespace App\Support\Publishing;

use App\Models\TelegramBot;

/**
 * Where a post goes: the account that sends it and the chat/address it goes to.
 */
final class PublishTarget
{
    public function __construct(
        public readonly string $platform,
        public readonly TelegramBot $account,
        public readonly string $address,
    ) {}

    public static function telegram(TelegramBot $bot, string $chatId): self
    {
        return new self('telegram', $bot, $chatId);
    }
}
