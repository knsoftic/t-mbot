<?php

namespace App\Livewire\PostingLogs;

use App\Models\Campaign;
use App\Models\PostingLog;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Posting Logs')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $campaign_id = '';

    #[Url(except: '')]
    public string $bot_id = '';

    #[Url(except: '')]
    public string $destination_id = '';

    #[Url(except: '')]
    public string $status = '';

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

    public function clearFilters(): void
    {
        $this->reset(['campaign_id', 'bot_id', 'destination_id', 'status', 'from', 'to']);
        $this->resetPage();
    }

    public function show(int $id): void
    {
        $this->detailId = $id;
        $this->showDetail = true;
    }

    public function render()
    {
        $filters = [
            'campaign_id' => $this->campaign_id, 'bot_id' => $this->bot_id, 'destination_id' => $this->destination_id,
            'status' => $this->status, 'from' => $this->from, 'to' => $this->to,
        ];

        $logs = PostingLog::query()
            ->with(['campaign:id,name,deleted_at', 'bot:id,name,deleted_at', 'destination:id,name,deleted_at', 'content:id,title,type,deleted_at'])
            ->filter(array_filter($filters), display_tz())
            ->latest('id')
            ->paginate(25);

        return view('livewire.posting-logs.index', [
            'logs' => $logs,
            'campaigns' => Campaign::withTrashed()->orderBy('name')->get(['id', 'name']),
            'bots' => TelegramBot::withTrashed()->orderBy('name')->get(['id', 'name']),
            'destinations' => TelegramDestination::withTrashed()->orderBy('name')->get(['id', 'name']),
            'detail' => $this->showDetail && $this->detailId
                ? PostingLog::query()->with(['campaign', 'bot', 'destination', 'content', 'campaignItem'])->find($this->detailId)
                : null,
            'filtered' => array_filter($filters) !== [],
        ]);
    }
}
