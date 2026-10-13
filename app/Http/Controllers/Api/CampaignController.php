<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CampaignResource;
use App\Http\Resources\PostingLogResource;
use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Services\CampaignBuilder;
use App\Services\CampaignService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly CampaignBuilder $builder,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize(Permissions::CAMPAIGNS_VIEW);

        $items = Campaign::query()
            ->with('schedules')
            ->search($request->query('search'))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('bot_id'), fn ($q, $v) => $q->where('bot_id', $v))
            ->when($request->query('destination_id'), fn ($q, $v) => $q->where('destination_id', $v))
            ->latest('updated_at')
            ->paginate(min(100, (int) $request->query('per_page', 20)));

        return CampaignResource::collection($items);
    }

    /**
     * Create a campaign with its sequence ("items") and time slots ("slots").
     * Dates are wall-clock times in the given "timezone". Pass "activate": true to start it.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permissions::CAMPAIGNS_MANAGE);
        $data = $request->validate(CampaignBuilder::rules());
        $campaign = $this->builder->save($data, null, $request->user());

        if ($request->boolean('activate')) {
            Gate::authorize(Permissions::CAMPAIGNS_OPERATE);
            $campaign = $this->campaigns->activate($campaign, $request->user());
        }

        return (new CampaignResource($campaign->load(['schedules', 'items.content'])))->response()->setStatusCode(201);
    }

    public function show(Campaign $campaign): CampaignResource
    {
        Gate::authorize(Permissions::CAMPAIGNS_VIEW);

        return new CampaignResource($campaign->load(['schedules', 'items.content:id,title']));
    }

    public function update(Request $request, Campaign $campaign): CampaignResource
    {
        Gate::authorize(Permissions::CAMPAIGNS_MANAGE);
        $data = $request->validate(CampaignBuilder::rules());

        return new CampaignResource($this->builder->save($data, $campaign, $request->user())->load(['schedules', 'items.content:id,title']));
    }

    public function destroy(Request $request, Campaign $campaign): JsonResponse
    {
        Gate::authorize(Permissions::CAMPAIGNS_MANAGE);
        $this->builder->deleteCampaign($campaign, $request->user());

        return response()->json(['message' => 'Campaign deleted.']);
    }

    public function activate(Request $request, Campaign $campaign): CampaignResource
    {
        return $this->operate(fn () => $this->campaigns->activate($campaign, $request->user()), $campaign);
    }

    public function run(Request $request, Campaign $campaign): JsonResponse
    {
        Gate::authorize(Permissions::CAMPAIGNS_OPERATE);
        $this->campaigns->runNow($campaign, $request->user());

        return response()->json(['message' => 'Run Now queued.', 'campaign' => new CampaignResource($campaign->refresh())], 202);
    }

    public function pause(Request $request, Campaign $campaign): CampaignResource
    {
        return $this->operate(fn () => $this->campaigns->pause($campaign, $request->user()), $campaign);
    }

    public function resume(Request $request, Campaign $campaign): CampaignResource
    {
        return $this->operate(fn () => $this->campaigns->resume($campaign, $request->user()), $campaign);
    }

    public function stop(Request $request, Campaign $campaign): CampaignResource
    {
        return $this->operate(fn () => $this->campaigns->stop($campaign, $request->user()), $campaign);
    }

    public function restart(Request $request, Campaign $campaign): CampaignResource
    {
        return $this->operate(fn () => $this->campaigns->restart($campaign, $request->user()), $campaign);
    }

    public function skipItem(Request $request, Campaign $campaign, CampaignItem $item): CampaignResource
    {
        abort_unless($item->campaign_id === $campaign->id, 404);

        return $this->operate(fn () => $this->campaigns->skipItem($item, $request->user()), $campaign);
    }

    public function retryItem(Request $request, Campaign $campaign, CampaignItem $item): CampaignResource
    {
        abort_unless($item->campaign_id === $campaign->id, 404);

        return $this->operate(fn () => $this->campaigns->retryItem($item, $request->user()), $campaign);
    }

    public function logs(Request $request, Campaign $campaign): AnonymousResourceCollection
    {
        Gate::authorize(Permissions::LOGS_VIEW);

        return PostingLogResource::collection(
            $campaign->postingLogs()->with('content:id,title')
                ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
                ->latest('id')
                ->paginate(min(100, (int) $request->query('per_page', 25)))
        );
    }

    private function operate(callable $action, Campaign $campaign): CampaignResource
    {
        Gate::authorize(Permissions::CAMPAIGNS_OPERATE);
        $action();

        return new CampaignResource($campaign->refresh()->load(['schedules', 'items.content:id,title']));
    }
}
