<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostingLogResource;
use App\Models\PostingLog;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PostingLogController extends Controller
{
    /**
     * Filters: campaign_id, bot_id, destination_id, status, from, to (Y-m-d, in the user's timezone).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize(Permissions::LOGS_VIEW);

        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:pending,processing,sent,failed,skipped'],
        ]);

        return PostingLogResource::collection(
            PostingLog::query()
                ->with('content:id,title')
                ->filter($request->only(['campaign_id', 'bot_id', 'destination_id', 'status', 'from', 'to']), $request->user()->displayTimezone())
                ->latest('id')
                ->paginate(min(100, (int) $request->query('per_page', 25)))
        );
    }
}
