<?php

namespace App\Livewire\AuditLogs;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Audit Logs')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $user = '';

    #[Url(except: '')]
    public string $area = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public ?int $detailId = null;

    public bool $showDetail = false;

    public function updated(): void
    {
        $this->resetPage();
    }

    public function show(int $id): void
    {
        $this->detailId = $id;
        $this->showDetail = true;
    }

    public function render()
    {
        $tz = display_tz();

        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('description', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%')
                ->orWhere('ip_address', 'like', $this->search.'%')))
            ->when($this->user !== '', fn ($q) => $this->user === 'system' ? $q->whereNull('user_id') : $q->where('user_id', $this->user))
            ->when($this->area !== '', fn ($q) => $q->where('action', 'like', $this->area.'.%'))
            ->when($this->from !== '', fn ($q) => $q->where('created_at', '>=', CarbonImmutable::parse($this->from, $tz)->startOfDay()->utc()))
            ->when($this->to !== '', fn ($q) => $q->where('created_at', '<=', CarbonImmutable::parse($this->to, $tz)->endOfDay()->utc()))
            ->latest('id')
            ->paginate(30);

        return view('livewire.audit-logs.index', [
            'logs' => $logs,
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'areas' => ['auth' => 'Authentication', 'bot' => 'Bots', 'destination' => 'Destinations', 'content' => 'Content', 'tag' => 'Tags',
                'campaign' => 'Campaigns', 'user' => 'Users', 'role' => 'Roles', 'settings' => 'Settings', 'failed_job' => 'Failed jobs', 'api' => 'API tokens'],
            'detail' => $this->showDetail && $this->detailId ? AuditLog::query()->with('user')->find($this->detailId) : null,
        ]);
    }
}
