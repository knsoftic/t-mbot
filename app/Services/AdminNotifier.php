<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AdminAlert;
use App\Support\Permissions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends AdminAlert notifications to every active user allowed to see campaigns.
 */
class AdminNotifier
{
    public function __construct(private readonly SettingsService $settings) {}

    public function send(AdminAlert $alert): void
    {
        try {
            $recipients = User::query()->active()->with('role')->get()
                ->filter(fn (User $user) => $user->hasPermission(Permissions::CAMPAIGNS_VIEW));

            if ($recipients->isEmpty()) {
                return;
            }

            $alert->channels = $this->channels();
            Notification::send($recipients, $alert);
        } catch (Throwable $e) {
            // Notifications must never break posting.
            Log::warning('Admin notification failed', ['category' => $alert->category, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return list<string>
     */
    public function channels(): array
    {
        $channels = ['database'];

        if ($this->settings->get('notifications.mail_enabled')) {
            $channels[] = 'mail';
        }

        return $channels;
    }
}
