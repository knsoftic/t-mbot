<?php

namespace App\Livewire\MemberImports;

use App\Enums\ImportRowStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\MemberImport;
use App\Services\MemberImportService;
use App\Support\Permissions;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Progress and per-person report of one member import.
 */
class Show extends Component
{
    use InteractsWithUi, WithPagination;

    #[Locked]
    public int $importId;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $search = '';

    public function mount(MemberImport $import): void
    {
        $this->authorize(Permissions::MEMBERS_IMPORT);
        $this->importId = $import->id;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function recheck(MemberImportService $imports): void
    {
        $this->authorize(Permissions::MEMBERS_IMPORT);
        $count = $imports->recheck(MemberImport::query()->findOrFail($this->importId), auth()->user());
        $this->toast($count > 0 ? "{$count} people are being checked again." : 'Nobody is waiting: nothing to check again.', $count > 0 ? 'success' : 'info');
    }

    public function render()
    {
        $import = MemberImport::query()->with(['destination', 'bot:id,name,username'])->findOrFail($this->importId);
        $counts = $import->counts();
        $total = array_sum($counts);
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], strtolower(ltrim(trim($this->search), '@'))).'%';

        $rows = $import->rows()
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($w) => $w->where('username', 'like', $term)->orWhere('input', 'like', $term)->orWhere('telegram_user_id', 'like', $term)))
            ->orderBy('row_number')
            ->paginate(50);

        return view('livewire.member-imports.show', [
            'import' => $import,
            'counts' => $counts,
            'total' => $total,
            'done' => $total - $counts[ImportRowStatus::Pending->value],
            'rows' => $rows,
        ])->title('Member import #'.$import->id);
    }
}
