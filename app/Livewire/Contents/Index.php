<?php

namespace App\Livewire\Contents;

use App\Enums\ContentStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Content;
use App\Models\Tag;
use App\Services\ContentService;
use App\Support\Permissions;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Content Library')]
class Index extends Component
{
    use InteractsWithUi, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $tag = '';

    public ?int $previewId = null;

    public bool $showPreview = false;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'type', 'status', 'tag'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'type', 'status', 'tag']);
        $this->resetPage();
    }

    public function preview(int $id): void
    {
        $this->previewId = $id;
        $this->showPreview = true;
    }

    public function duplicate(int $id, ContentService $contents): void
    {
        $this->authorize(Permissions::CONTENTS_MANAGE);
        $copy = $contents->duplicate(Content::query()->findOrFail($id), auth()->user());
        $this->toast("Duplicated as “{$copy->title}”.");
    }

    public function toggle(int $id, ContentService $contents): void
    {
        $this->authorize(Permissions::CONTENTS_MANAGE);
        $content = Content::query()->findOrFail($id);
        $new = $content->isActive() ? ContentStatus::Inactive : ContentStatus::Active;
        $contents->setStatus($content, $new, auth()->user());
        $this->toast("“{$content->title}” ".($new === ContentStatus::Active ? 'activated' : 'deactivated').'.');
    }

    public function delete(int $id, ContentService $contents): void
    {
        $this->authorize(Permissions::CONTENTS_MANAGE);
        $content = Content::query()->findOrFail($id);
        $this->attempt(fn () => $contents->delete($content, auth()->user()), "“{$content->title}” deleted.");
    }

    public function render()
    {
        $contents = Content::query()
            ->with('tags:id,name,color')
            ->withCount('campaignItems')
            ->search($this->search)
            ->when($this->type !== '', fn ($q) => $q->where('type', $this->type))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->tag !== '', fn ($q) => $q->whereHas('tags', fn ($t) => $t->where('tags.id', $this->tag)))
            ->latest()
            ->paginate(20);

        return view('livewire.contents.index', [
            'contents' => $contents,
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name']),
            'previewContent' => $this->showPreview && $this->previewId ? Content::query()->with('tags')->find($this->previewId) : null,
            'filtered' => $this->search !== '' || $this->type !== '' || $this->status !== '' || $this->tag !== '',
        ]);
    }
}
