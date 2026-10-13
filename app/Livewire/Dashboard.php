<?php

namespace App\Livewire;

use App\Enums\BotStatus;
use App\Enums\CampaignStatus;
use App\Enums\DestinationType;
use App\Enums\ItemStatus;
use App\Enums\PostingStatus;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\Content;
use App\Models\PostingLog;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    public function mount(): void
    {
        $this->authorize(Permissions::DASHBOARD_VIEW);
    }

    /**
     * @return array<string, int>
     */
    private function stats(): array
    {
        $todayStart = CarbonImmutable::now(display_tz())->startOfDay()->utc();
        $campaignCounts = Campaign::query()->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $destinationCounts = TelegramDestination::query()->toBase()->whereNull('deleted_at')->selectRaw('type, count(*) as aggregate')->groupBy('type')->pluck('aggregate', 'type');
        $postsToday = PostingLog::query()->where('created_at', '>=', $todayStart)->toBase()
            ->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return [
            'bots' => TelegramBot::query()->count(),
            'active_bots' => TelegramBot::query()->where('status', BotStatus::Active)->count(),
            'channels' => (int) ($destinationCounts[DestinationType::Channel->value] ?? 0),
            'groups' => (int) ($destinationCounts[DestinationType::Group->value] ?? 0) + (int) ($destinationCounts[DestinationType::Supergroup->value] ?? 0),
            'contents' => Content::query()->count(),
            'running' => (int) ($campaignCounts[CampaignStatus::Running->value] ?? 0),
            'scheduled' => (int) ($campaignCounts[CampaignStatus::Scheduled->value] ?? 0),
            'completed' => (int) ($campaignCounts[CampaignStatus::Completed->value] ?? 0),
            'paused' => (int) ($campaignCounts[CampaignStatus::Paused->value] ?? 0),
            'sent_today' => (int) ($postsToday[PostingStatus::Sent->value] ?? 0),
            'failed_today' => (int) ($postsToday[PostingStatus::Failed->value] ?? 0),
            'pending' => CampaignItem::query()
                ->where('status', ItemStatus::Pending)
                ->whereHas('campaign', fn ($q) => $q->whereIn('status', [CampaignStatus::Scheduled, CampaignStatus::Running, CampaignStatus::Paused]))
                ->count(),
        ];
    }

    /**
     * Posting results and campaign lifecycle events, newest first.
     *
     * @return Collection<int, array{at: \DateTimeInterface, tone: string, icon: string, text: string, url: ?string}>
     */
    private function activity(): Collection
    {
        $posts = PostingLog::query()
            ->with(['content:id,title,type', 'destination:id,name', 'campaign:id,name'])
            ->whereIn('status', [PostingStatus::Sent, PostingStatus::Failed, PostingStatus::Skipped])
            ->latest('id')->limit(12)->get()
            ->map(fn (PostingLog $log) => [
                'at' => $log->sent_at ?? $log->updated_at,
                'tone' => $log->status->color(),
                'icon' => match ($log->status) {
                    PostingStatus::Sent => 'check-circle',
                    PostingStatus::Failed => 'x-circle',
                    default => 'forward',
                },
                'text' => match ($log->status) {
                    PostingStatus::Sent => "“{$log->content?->title}” sent successfully to {$log->destination?->name}".($log->is_test ? ' (test mode)' : ''),
                    PostingStatus::Failed => "“{$log->content?->title}” failed: ".str($log->error_message)->limit(90),
                    default => "“{$log->content?->title}” skipped",
                },
                'url' => $log->campaign ? route('campaigns.show', $log->campaign_id) : null,
            ]);

        $events = AuditLog::query()
            ->with('user:id,name')
            ->whereIn('action', ['campaign.started', 'campaign.paused', 'campaign.resumed', 'campaign.stopped', 'campaign.restarted', 'campaign.run_now'])
            ->latest('id')->limit(8)->get()
            ->map(fn (AuditLog $log) => [
                'at' => $log->created_at,
                'tone' => 'blue',
                'icon' => 'bolt',
                'text' => $log->description.($log->user ? ' by '.$log->user->name : ''),
                'url' => $log->entity_id ? route('campaigns.show', $log->entity_id) : null,
            ]);

        return $posts->concat($events)->sortByDesc('at')->take(14)->values();
    }

    public function render()
    {
        return view('livewire.dashboard', [
            'stats' => $this->stats(),
            'activity' => $this->activity(),
            'upcoming' => Campaign::query()
                ->with(['destination:id,name', 'bot:id,name'])
                ->whereIn('status', CampaignStatus::active())
                ->whereNotNull('next_run_at')
                ->orderBy('next_run_at')
                ->limit(6)
                ->get(),
            'attention' => Campaign::query()->where('status', CampaignStatus::Failed)->latest('updated_at')->limit(5)->get(['id', 'name', 'last_error']),
        ]);
    }
}
