<?php

namespace App\Livewire\Tags;

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Tag;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Categories / Tags')]
class Index extends Component
{
    use InteractsWithUi, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public ?int $editingId = null;

    public string $name = '';

    public string $color = 'slate';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function edit(int $id): void
    {
        $this->authorize(Permissions::CONTENTS_MANAGE);
        $tag = Tag::query()->findOrFail($id);
        $this->editingId = $tag->id;
        $this->name = $tag->name;
        $this->color = $tag->color;
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'name']);
        $this->color = 'slate';
        $this->resetValidation();
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize(Permissions::CONTENTS_MANAGE);

        $this->validate([
            'name' => ['required', 'string', 'max:50', function ($attribute, $value, $fail) {
                $exists = Tag::query()->where('slug', Str::slug($value))->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))->exists();

                if ($exists || Str::slug($value) === '') {
                    $fail($exists ? 'A tag with this name already exists.' : 'Use letters or numbers in the tag name.');
                }
            }],
            'color' => ['required', Rule::in(Tag::COLORS)],
        ]);

        $tag = $this->editingId ? Tag::query()->findOrFail($this->editingId) : new Tag;
        $tag->fill(['name' => trim($this->name), 'color' => $this->color])->save();
        $audit->log($this->editingId ? 'tag.updated' : 'tag.created', $tag, "Tag “{$tag->name}” saved");

        $this->toast("Tag “{$tag->name}” saved.");
        $this->cancel();
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $this->authorize(Permissions::CONTENTS_MANAGE);
        $tag = Tag::query()->findOrFail($id);
        $tag->delete();
        $audit->log('tag.deleted', $tag, "Tag “{$tag->name}” deleted");
        $this->toast("Tag “{$tag->name}” deleted. Content items keep working.");
    }

    public function render()
    {
        return view('livewire.tags.index', [
            'tags' => Tag::query()
                ->withCount('contents')
                ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%'))
                ->orderBy('name')
                ->paginate(30),
        ]);
    }
}
