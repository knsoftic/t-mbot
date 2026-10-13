<?php

namespace App\Livewire\Campaigns;

use App\Enums\ItemStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Campaign;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Services\CampaignService;
use App\Support\Permissions;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Campaigns')]
class Index extends Component
{
    use InteractsWithUi, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(as: 'bot', except: '')]
    public string $botFilter = '';

    #[Url(as: 'destination', except: '')]
    public string $destinationFilter = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'botFilter', 'destinationFilter'], true)) {
            $this->resetPage();
        }
    }

    public function runNow(int $id, CampaignService $campaigns): void
    {
        $this->authorize(Permissions::CAMPAIGNS_OPERATE);
        $campaign = Campaign::query()->findOrFail($id);
        $this->attempt(fn () => $campaigns->runNow($campaign, auth()->user()), "Run Now queued for “{$campaign->name}”.");
    }

    public function pause(int $id, CampaignService $campaigns): void
    {
        $this->authorize(Permissions::CAMPAIGNS_OPERATE);
        $campaign = Campaign::query()->findOrFail($id);
        $this->attempt(fn () => $campaigns->pause($campaign, auth()->user()), "“{$campaign->name}” paused.");
    }

    public function resume(int $id, CampaignService $campaigns): void
    {
        $this->authorize(Permissions::CAMPAIGNS_OPERATE);
        $campaign = Campaign::query()->findOrFail($id);
        $this->attempt(fn () => $campaigns->resume($campaign, auth()->user()), "“{$campaign->name}” resumed.");
    }

    public function render()
    {
        $campaigns = Campaign::query()
            ->with(['bot:id,name,deleted_at', 'destination:id,name,type,deleted_at', 'schedules'])
            ->withCount([
                'campaignItems as items_total',
                'campaignItems as items_done' => fn ($q) => $q->whereIn('status', [ItemStatus::Sent, ItemStatus::Failed, ItemStatus::Skipped]),
                'campaignItems as items_failed' => fn ($q) => $q->where('status', ItemStatus::Failed),
            ])
            ->search($this->search)
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->botFilter !== '', fn ($q) => $q->where('bot_id', $this->botFilter))
            ->when($this->destinationFilter !== '', fn ($q) => $q->where('destination_id', $this->destinationFilter))
            ->latest('updated_at')
            ->paginate(15);

        $hasRunning = $campaigns->contains(fn (Campaign $c) => $c->status->isActive());

        return view('livewire.campaigns.index', [
            'campaigns' => $campaigns,
            'bots' => TelegramBot::query()->orderBy('name')->get(['id', 'name']),
            'destinations' => TelegramDestination::query()->orderBy('name')->get(['id', 'name']),
            'poll' => $hasRunning,
        ]);
    }
}
