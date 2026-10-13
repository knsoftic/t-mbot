<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContentResource;
use App\Models\Content;
use App\Services\ContentService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ContentController extends Controller
{
    public function __construct(private readonly ContentService $contents) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize(Permissions::CONTENTS_VIEW);

        $items = Content::query()
            ->with('tags:id,name')
            ->search($request->query('search'))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('tag'), fn ($q, $v) => $q->whereHas('tags', fn ($t) => $t->where('slug', $v)))
            ->latest()
            ->paginate(min(100, (int) $request->query('per_page', 20)));

        return ContentResource::collection($items);
    }

    /**
     * JSON (text / media URL / file_id) or multipart with an "upload" file.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permissions::CONTENTS_MANAGE);
        $request->mergeIfMissing(['status' => 'active', 'source' => $request->input('type') === 'text' ? 'none' : null]);
        $data = $request->validate($this->contents->rules($request->input('type'), $request->input('source')));
        $content = $this->contents->create($data, $request->file('upload'), $request->user());

        return (new ContentResource($content->load('tags')))->response()->setStatusCode(201);
    }

    public function show(Content $content): ContentResource
    {
        Gate::authorize(Permissions::CONTENTS_VIEW);

        return new ContentResource($content->load('tags'));
    }

    public function update(Request $request, Content $content): ContentResource
    {
        Gate::authorize(Permissions::CONTENTS_MANAGE);

        // Partial updates: missing fields keep their current values.
        $request->merge(array_merge([
            'title' => $content->title,
            'type' => $content->type->value,
            'source' => $content->source->value,
            'text' => $content->text,
            'caption' => $content->caption,
            'parse_mode' => $content->parse_mode?->value,
            'media_url' => $content->media_url,
            'telegram_file_id' => $content->telegram_file_id,
            'status' => $content->status->value,
            'tags' => $content->tags()->pluck('name')->all(),
        ], $request->all()));

        $hasUpload = $content->media_path !== null;
        $data = $request->validate($this->contents->rules($request->input('type'), $request->input('source'), $hasUpload));

        return new ContentResource($this->contents->update($content, $data, $request->file('upload'), $request->user())->load('tags'));
    }

    public function destroy(Request $request, Content $content): JsonResponse
    {
        Gate::authorize(Permissions::CONTENTS_MANAGE);
        $this->contents->delete($content, $request->user());

        return response()->json(['message' => 'Content deleted.']);
    }
}
