<?php

namespace App\Console\Commands;

use App\Models\TelegramBot;
use App\Services\BotService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bots:check')]
#[Description('Verify every active bot token with getMe (alerts admins when a bot gets disconnected)')]
class CheckBots extends Command
{
    public function handle(BotService $bots): int
    {
        TelegramBot::query()->active()->orderBy('id')->each(function (TelegramBot $bot) use ($bots) {
            $result = $bots->test($bot);
            $this->line(sprintf('%-30s %s', $bot->name, $result['ok'] ? '<info>OK</info>' : '<error>'.$result['message'].'</error>'));
        });

        return self::SUCCESS;
    }
}
