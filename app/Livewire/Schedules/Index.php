<?php

namespace App\Livewire\Schedules;

use App\Enums\CampaignStatus;
use App\Enums\ItemStatus;
use App\Models\Campaign;
use App\Models\TelegramBot;
use App\Services\ScheduleCalculator;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Upcoming posting times across all campaigns.
 */
#[Title('Schedules')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'bot', except: '')]
    public string $botFilter = '';

    #[Url(except: 'active')]
    public string $scope = 'active';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $calculator = app(ScheduleCalculator::class);
        $statuses = match ($this->scope) {
            'paused' => [CampaignStatus::Paused],
            'all' => CampaignStatus::cases(),
            default => CampaignStatus::active(),
        };

        $campaigns = Campaign::query()
            ->with(['bot:id,name,deleted_at', 'destination:id,name,deleted_at', 'schedules'])
            ->withCount(['campaignItems as pending_count' => fn ($q) => $q->where('status', ItemStatus::Pending)])
            ->whereIn('status', $statuses)
            ->when($this->botFilter !== '', fn ($q) => $q->where('bot_id', $this->botFilter))
            ->orderByRaw('next_run_at is null')
            ->orderBy('next_run_at')
            ->paginate(20);

        $now = CarbonImmutable::now();

        // Next few run times per campaign (only as many as there are pending items).
        $upcoming = $campaigns->getCollection()->mapWithKeys(function (Campaign $campaign) use ($calculator) {
            if (! $campaign->status->isActive() || ! $campaign->next_run_at) {
                return [$campaign->id => []];
            }

            $runs = [$campaign->next_run_at];
            $limit = min(4, max(1, $campaign->pending_count));

            while (count($runs) < $limit && ($next = $calculator->nextRun($campaign, end($runs)))) {
                $runs[] = $next;
            }

            return [$campaign->id => $runs];
        });

        return view('livewire.schedules.index', [
            'campaigns' => $campaigns,
            'upcoming' => $upcoming,
            'bots' => TelegramBot::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
