<?php

namespace App\Listeners;

use App\Events\BotConnectionFailed;
use App\Events\DestinationPermissionFailed;
use App\Notifications\AdminAlert;
use App\Services\AdminNotifier;
use Illuminate\Support\Facades\Cache;

class NotifyConnectionProblems
{
    public function __construct(private readonly AdminNotifier $notifier) {}

    public function handleBot(BotConnectionFailed $event): void
    {
        if (Cache::add("alerts:bot:{$event->bot->id}", true, now()->addHour())) {
            $this->notifier->send(AdminAlert::botDisconnected($event->bot, $event->message), $event->bot->user_id);
        }
    }

    public function handleDestination(DestinationPermissionFailed $event): void
    {
        if (Cache::add("alerts:destination:{$event->destination->id}", true, now()->addHour())) {
            $this->notifier->send(AdminAlert::permissionFailure($event->destination, $event->message), $event->destination->user_id);
        }
    }
}
