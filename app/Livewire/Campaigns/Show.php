<?php

namespace App\Livewire\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\ItemStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\Content;
use App\Models\PostingLog;
use App\Services\CampaignBuilder;
use App\Services\CampaignService;
use App\Support\Permissions;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class Show extends Component
{
    use InteractsWithUi, WithPagination;

    #[Locked]
    public int $campaignId;

    public bool $showAdd = false;

    public string $addSearch = '';

    /** @var list<int|string> */
    public array $addSelected = [];

    public ?int $previewContentId = null;

    public bool $showPreview = false;

    public function mount(Campaign $campaign): void
    {
        $this->campaignId = $campaign->id;
    }

    private function campaign(): Campaign
    {
        return Campaign::query()->findOrFail($this->campaignId);
    }

    private function item(int $id): CampaignItem
    {
        return CampaignItem::query()->where('campaign_id', $this->campaignId)->findOrFail($id);
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    public function runNow(CampaignService $service): void
    {
        $this->authorize(Permissions::CAMPAIGNS_OPERATE);
        $this->attempt(fn () => $service->runNow($this->campaign(), auth()->user()), 'Run Now queued — the next item is being posted.');
    }

    public function activate(CampaignService $service): void
    {
        $this->authorize(Permissions::CAMPAIGNS_OPERATE);
        $this->attempt(fn () => $service->activate($this->campaign(), auth()->user()), 'Campaign activated.');
    }

    public function pause(CampaignService $service): void
    {
        $this->authorize(Permissions::CAMPAIGNS_OPERATE);
        $this->attempt(fn () => $service->pause($this->campaign(), auth()->user()), 'Campaign paused. No new posts will be sent.');
    }

    public function resume(CampaignService $service): void
    {
        $this->authorize(Permissions::CAMPAIGNS_OPERATE);
        $this->attempt(fn () => $service->resume($this->campaign(), auth()->user()), 'Campaign resumed from the next pending item.');
    }

    public function stop(CampaignService $service): void
    {
        $this->authorize(Permissions::CAMPAIGNS_OPERATE);
        $this->attempt(fn () => $service->stop($this->campaign(), auth()->user()), 'Campaign stopped.');
    }

    public function restart(CampaignService $service): void
    {
        $this->authorize(Permissions::CAMPAIGNS_OPERATE);
        $this->attempt(fn () => $service->restart($this->campaign(), auth()->user()), 'Campaign restarted from item #1. Posting history was kept.');
    }

    public function delete(CampaignBuilder $builder): void
    {
        $this->authorize(Permissions::CAMPAIGNS_MANAGE);
        $campaign = $this->campaign();

        if ($this->attempt(fn () => $builder->deleteCampaign($campaign, auth()->user()))) {
            $this->flash("Campaign “{$campaign->name}” deleted.");
            $this->redirectRoute('campaigns.index', navigate: true);
        }
    }

    // ------------------------------------------------------------------
    // Items
    // ------------------------------------------------------------------

    public function skip(int $itemId, CampaignService $service): void
    {
        $this->authorize(Permissions::CAMPAIGNS_OPERATE);
        $this->attempt(fn () => $service->skipItem($this->item($itemId), auth()->user()), 'Item skipped.');
    }

    public function retry(int $itemId, CampaignService $service): void
    {
        $this->authorize(Permissions::CAMPAIGNS_OPERATE);
        $this->attempt(fn () => $service->retryItem($this->item($itemId), auth()->user()), 'Item queued again — it will be posted at the next run (or use Run Now).');
    }

    public function reorder(int|string $itemId, int $position, CampaignBuilder $builder): void
    {
        $this->authorize(Permissions::CAMPAIGNS_MANAGE);
        $builder->moveTo($this->item((int) $itemId), $position);
    }

    public function move(int $itemId, int $delta, CampaignBuilder $builder): void
    {
        $this->authorize(Permissions::CAMPAIGNS_MANAGE);
        $builder->moveBy($this->item($itemId), $delta);
    }

    public function duplicateItem(int $itemId, CampaignBuilder $builder): void
    {
        $this->authorize(Permissions::CAMPAIGNS_MANAGE);
        $builder->duplicateItem($this->item($itemId));
        $this->toast('Item duplicated.');
    }

    public function removeItem(int $itemId, CampaignBuilder $builder): void
    {
        $this->authorize(Permissions::CAMPAIGNS_MANAGE);
        $this->attempt(fn () => $builder->removeItem($this->item($itemId), auth()->user()), 'Item removed from the sequence.');
    }

    public function openAdd(): void
    {
        $this->authorize(Permissions::CAMPAIGNS_MANAGE);
        $this->reset(['addSearch', 'addSelected']);
        $this->showAdd = true;
    }

    public function addSelectedContent(CampaignBuilder $builder): void
    {
        $this->authorize(Permissions::CAMPAIGNS_MANAGE);
        $count = $builder->addContents($this->campaign(), array_map('intval', $this->addSelected), auth()->user());
        $this->showAdd = false;
        $this->addSelected = [];
        $this->toast("{$count} item(s) added to the end of the sequence.");
    }

    public function preview(int $contentId): void
    {
        $this->previewContentId = $contentId;
        $this->showPreview = true;
    }

    public function updatedAddSearch(): void
    {
        $this->resetPage('addPage');
    }

    public function render()
    {
        $service = app(CampaignService::class);
        $campaign = Campaign::query()
            ->with(['bot', 'destination', 'testDestination', 'schedules', 'creator:id,name'])
            ->findOrFail($this->campaignId);

        $items = $campaign->items()->with(['content:id,title,type,status,caption,text,deleted_at'])->get();
        $campaign->setRelation('items', $items);
        $progress = $campaign->progress();
        $nextItem = $items->first(fn (CampaignItem $i) => $i->status === ItemStatus::Pending);
        $lastLog = PostingLog::query()->where('campaign_id', $campaign->id)->whereNotNull('sent_at')->latest('sent_at')->first();
        $inFlight = $items->contains(fn (CampaignItem $i) => $i->status === ItemStatus::Processing || $i->isWaitingForRetry());

        return view('livewire.campaigns.show', [
            'campaign' => $campaign,
            'items' => $items,
            'progress' => $progress,
            'nextItem' => $nextItem,
            'lastLog' => $lastLog,
            'problems' => in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Paused, CampaignStatus::Failed], true) ? $service->readinessProblems($campaign) : [],
            'recentLogs' => PostingLog::query()->with('content:id,title')->where('campaign_id', $campaign->id)->latest('id')->limit(8)->get(),
            'addContents' => $this->showAdd ? Content::query()->active()->search($this->addSearch)->latest()->paginate(10, pageName: 'addPage') : null,
            'previewContent' => $this->showPreview && $this->previewContentId ? Content::withTrashed()->find($this->previewContentId) : null,
            'poll' => $campaign->status->isActive() || $inFlight,
        ])->title($campaign->name);
    }
}
