<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BotResource;
use App\Models\TelegramBot;
use App\Services\BotService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class BotController extends Controller
{
    public function __construct(private readonly BotService $bots) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize(Permissions::BOTS_VIEW);

        $bots = TelegramBot::query()
            ->withCount('destinations')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('username', 'like', "%{$s}%")))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(min(100, (int) $request->query('per_page', 20)));

        return BotResource::collection($bots);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permissions::BOTS_MANAGE);
        $data = $request->validate(BotService::rules(), BotService::messages());

        return (new BotResource($this->bots->create($data, $request->user())))->response()->setStatusCode(201);
    }

    public function show(TelegramBot $bot): BotResource
    {
        Gate::authorize(Permissions::BOTS_VIEW);

        return new BotResource($bot->loadCount('destinations'));
    }

    public function update(Request $request, TelegramBot $bot): BotResource
    {
        Gate::authorize(Permissions::BOTS_MANAGE);
        $data = $request->validate(BotService::rules($bot), BotService::messages());

        return new BotResource($this->bots->update($bot, $data, $request->user()));
    }

    public function destroy(Request $request, TelegramBot $bot): JsonResponse
    {
        Gate::authorize(Permissions::BOTS_MANAGE);
        $this->bots->delete($bot, $request->user());

        return response()->json(['message' => 'Bot deleted.']);
    }

    public function test(TelegramBot $bot): JsonResponse
    {
        Gate::authorize(Permissions::BOTS_MANAGE);
        $result = $this->bots->test($bot);

        return response()->json($result + ['bot' => new BotResource($bot->refresh())], $result['ok'] ? 200 : 422);
    }
}
