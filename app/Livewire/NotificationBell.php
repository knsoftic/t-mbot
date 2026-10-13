<?php

namespace App\Livewire;

use Illuminate\Notifications\DatabaseNotification;
use Livewire\Component;

/**
 * Header bell: unread admin alerts (campaign completed/failed, bot disconnected, ...).
 */
class NotificationBell extends Component
{
    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
    }

    public function open(string $id): void
    {
        /** @var DatabaseNotification|null $notification */
        $notification = auth()->user()->notifications()->whereKey($id)->first();

        if (! $notification) {
            return;
        }

        $notification->markAsRead();
        $url = $notification->data['url'] ?? null;

        // Only follow internal links stored by our own notifications.
        if (is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $this->redirect($url, navigate: true);
        }
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.notification-bell', [
            'unread' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()->latest()->limit(8)->get(),
        ]);
    }
}
