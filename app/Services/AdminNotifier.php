<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AdminAlert;
use App\Support\Permissions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Delivers AdminAlert notifications.
 *
 * Alerts about a customer's bots and campaigns go to that customer only;
 * alerts about staff-owned data go to staff who can see campaigns.
 */
class AdminNotifier
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * An alert about data owned by $ownerId (a bot, destination or campaign).
     */
    public function send(AdminAlert $alert, ?int $ownerId = null): void
    {
        $owner = $ownerId ? User::query()->with('role')->find($ownerId) : null;

        if ($owner && $owner->isCustomer()) {
            $this->toUser($owner, $alert);

            return;
        }

        $this->toStaff($alert, Permissions::CAMPAIGNS_VIEW);
    }

    /**
     * Every active staff member holding $permission.
     */
    public function toStaff(AdminAlert $alert, string $permission): void
    {
        try {
            $recipients = User::query()->active()->with('role')->get()
                ->filter(fn (User $user) => $user->seesAllAccounts() && $user->hasPermission($permission));
        } catch (Throwable $e) {
            Log::warning('Admin notification failed', ['category' => $alert->category, 'error' => $e->getMessage()]);

            return;
        }

        $this->deliver($recipients->all(), $alert);
    }

    public function toUser(User $user, AdminAlert $alert): void
    {
        if ($user->isActive()) {
            $this->deliver([$user], $alert);
        }
    }

    /**
     * @param  list<User>|array<int, User>  $recipients
     */
    private function deliver(array $recipients, AdminAlert $alert): void
    {
        if ($recipients === []) {
            return;
        }

        try {
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
