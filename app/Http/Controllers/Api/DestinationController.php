<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DestinationResource;
use App\Models\TelegramDestination;
use App\Services\DestinationService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DestinationController extends Controller
{
    public function __construct(private readonly DestinationService $destinations) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize(Permissions::DESTINATIONS_VIEW);

        $items = TelegramDestination::query()
            ->with('bot:id,name')
            ->when($request->query('bot_id'), fn ($q, $v) => $q->where('bot_id', $v))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('chat_id', 'like', "%{$s}%")))
            ->latest()
            ->paginate(min(100, (int) $request->query('per_page', 20)));

        return DestinationResource::collection($items);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permissions::DESTINATIONS_MANAGE);
        $data = $request->validate(DestinationService::rules(), DestinationService::messages());

        return (new DestinationResource($this->destinations->create($data, $request->user())))->response()->setStatusCode(201);
    }

    public function show(TelegramDestination $destination): DestinationResource
    {
        Gate::authorize(Permissions::DESTINATIONS_VIEW);

        return new DestinationResource($destination->load('bot'));
    }

    public function update(Request $request, TelegramDestination $destination): DestinationResource
    {
        Gate::authorize(Permissions::DESTINATIONS_MANAGE);
        $data = $request->validate(DestinationService::rules($destination), DestinationService::messages());

        return new DestinationResource($this->destinations->update($destination, $data, $request->user()));
    }

    public function destroy(Request $request, TelegramDestination $destination): JsonResponse
    {
        Gate::authorize(Permissions::DESTINATIONS_MANAGE);
        $this->destinations->delete($destination, $request->user());

        return response()->json(['message' => 'Destination deleted.']);
    }

    /**
     * Queue a connection test (poll GET /destinations/{id} for connection_status).
     */
    public function test(Request $request, TelegramDestination $destination): JsonResponse
    {
        Gate::authorize(Permissions::DESTINATIONS_MANAGE);
        $this->destinations->queueTest($destination, $request->boolean('send_message', true), $request->user());

        return response()->json(['message' => 'Test queued.', 'destination' => new DestinationResource($destination->refresh())], 202);
    }
}
