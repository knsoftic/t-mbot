<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Models\PlanOrder;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts shown in the admin panel's notification menu (database channel)
 * and, when enabled in settings, sent by e-mail.
 *
 * New delivery channels (e.g. a Telegram admin chat) only need a toX()
 * method here and an entry in AdminNotifier::channels().
 */
class AdminAlert extends Notification
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public string $category,
        public string $level,
        public string $title,
        public string $message,
        public ?string $url = null,
        public array $channels = ['database'],
    ) {}

    public static function campaignStarted(Campaign $campaign): self
    {
        return new self('campaign_started', 'info', 'Campaign started', "Campaign \"{$campaign->name}\" is now {$campaign->status->label()}.", route('campaigns.show', $campaign, false));
    }

    public static function campaignCompleted(Campaign $campaign): self
    {
        return new self('campaign_completed', 'success', 'Campaign completed', "Campaign \"{$campaign->name}\" posted all of its content.", route('campaigns.show', $campaign, false));
    }

    public static function campaignFailed(Campaign $campaign): self
    {
        return new self('campaign_failed', 'danger', 'Campaign failed', "Campaign \"{$campaign->name}\" stopped: ".($campaign->last_error ?: 'see the posting logs.'), route('campaigns.show', $campaign, false));
    }

    public static function botDisconnected(TelegramBot $bot, string $reason): self
    {
        return new self('bot_disconnected', 'danger', 'Bot disconnected', "Bot \"{$bot->name}\" cannot connect to Telegram: {$reason}", route('bots.index', [], false));
    }

    public static function permissionFailure(TelegramDestination $destination, string $reason): self
    {
        return new self('permission_failure', 'danger', 'Telegram permission problem', "\"{$destination->name}\": {$reason}", route('destinations.index', [], false));
    }

    public static function repeatedFailures(Campaign $campaign, int $count): self
    {
        return new self('repeated_failures', 'warning', 'Repeated posting failures', "The last {$count} posts of \"{$campaign->name}\" failed.", route('logs.index', ['campaign_id' => $campaign->id], false));
    }

    public static function sendingLimitReached(string $message): self
    {
        return new self('sending_limit', 'warning', 'Posting limit reached', $message, route('billing.index', [], false));
    }

    public static function orderSubmitted(PlanOrder $order): self
    {
        return new self('order_submitted', 'info', 'New plan purchase request', "{$order->contact_name} ({$order->user?->email}) requests {$order->plan?->name} for \${$order->amount_usd} via {$order->payment_method_name}. Verify the payment and approve or reject it.", route('admin.orders.index', ['order' => $order->id], false));
    }

    public static function orderApproved(PlanOrder $order): self
    {
        return new self('order_approved', 'success', 'Plan activated', "Your payment was verified: {$order->plan?->name} is active until {$order->expires_at?->setTimezone($order->user?->displayTimezone() ?? 'UTC')->format('d M Y')}.", route('billing.index', [], false));
    }

    public static function orderRejected(PlanOrder $order): self
    {
        return new self('order_rejected', 'danger', 'Plan request rejected', "Your {$order->plan?->name} request #{$order->id} was rejected".($order->admin_note ? ": {$order->admin_note}" : '.'), route('billing.index', [], false));
    }

    public static function planExpiring(User $user, int $days): self
    {
        return new self('plan_expiring', 'warning', 'Plan expires soon', "Your {$user->plan?->name} plan expires in {$days} day(s) on {$user->plan_expires_at?->setTimezone($user->displayTimezone())->format('d M Y')}. Renew it to keep your limits.", route('billing.index', [], false));
    }

    public static function planExpired(string $planName, ?string $fallbackName): self
    {
        return new self('plan_expired', 'danger', 'Plan expired', "Your {$planName} plan has expired. Your account now uses the ".($fallbackName ?? 'free').' plan limits. Renew any time on the Billing page.', route('billing.index', [], false));
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'level' => $this->level,
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->line($this->message);

        if ($this->url) {
            $mail->action('Open admin panel', url($this->url));
        }

        return $this->level === 'danger' ? $mail->error() : $mail;
    }
}
