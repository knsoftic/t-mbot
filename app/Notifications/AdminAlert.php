<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
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
