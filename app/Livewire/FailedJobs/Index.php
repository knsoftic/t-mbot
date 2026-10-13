<?php

namespace App\Livewire\FailedJobs;

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Campaign;
use App\Models\PostingLog;
use App\Services\AuditLogger;
use App\Services\Telegram\TelegramService;
use App\Support\Permissions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Laravel's failed_jobs table, with links back to the campaign / content.
 */
#[Title('Failed Jobs')]
class Index extends Component
{
    use InteractsWithUi, WithPagination;

    public ?string $detailUuid = null;

    public bool $showDetail = false;

    public function mount(): void
    {
        $this->authorize(Permissions::FAILED_JOBS_MANAGE);
    }

    public function show(string $uuid): void
    {
        $this->detailUuid = $uuid;
        $this->showDetail = true;
    }

    public function retry(string $uuid, AuditLogger $audit): void
    {
        $this->authorize(Permissions::FAILED_JOBS_MANAGE);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $audit->log('failed_job.retried', null, "Failed job {$uuid} pushed back onto the queue");
        $this->showDetail = false;
        $this->toast('Job pushed back onto the queue.');
    }

    public function delete(string $uuid, AuditLogger $audit): void
    {
        $this->authorize(Permissions::FAILED_JOBS_MANAGE);
        Artisan::call('queue:forget', ['id' => $uuid]);
        $audit->log('failed_job.deleted', null, "Failed job {$uuid} deleted");
        $this->showDetail = false;
        $this->toast('Failed job deleted.');
    }

    public function retryAll(AuditLogger $audit): void
    {
        $this->authorize(Permissions::FAILED_JOBS_MANAGE);
        Artisan::call('queue:retry', ['id' => ['all']]);
        $audit->log('failed_job.retried_all', null, 'All failed jobs pushed back onto the queue');
        $this->toast('All failed jobs were pushed back onto the queue.');
    }

    public function flush(AuditLogger $audit): void
    {
        $this->authorize(Permissions::FAILED_JOBS_MANAGE);
        Artisan::call('queue:flush');
        $audit->log('failed_job.flushed', null, 'All failed jobs deleted');
        $this->toast('All failed jobs deleted.');
    }

    /**
     * @return array{name: string, campaign_id: ?int, log_id: ?int, attempts: ?int}
     */
    public static function describe(object $job): array
    {
        $payload = json_decode((string) $job->payload, true) ?: [];
        $command = (string) data_get($payload, 'data.command', '');

        // Read ids from the serialized command without unserializing it.
        preg_match('/"campaignId";i:(\d+)/', $command, $campaign);
        preg_match('/"postingLogId";i:(\d+)/', $command, $log);

        return [
            'name' => class_basename((string) data_get($payload, 'displayName', 'Job')),
            'campaign_id' => isset($campaign[1]) ? (int) $campaign[1] : null,
            'log_id' => isset($log[1]) ? (int) $log[1] : null,
            'attempts' => data_get($payload, 'attempts'),
        ];
    }

    public function render()
    {
        $jobs = DB::table(config('queue.failed.table', 'failed_jobs'))->orderByDesc('id')->paginate(20);

        $logIds = collect($jobs->items())->map(fn ($j) => self::describe($j)['log_id'])->filter()->unique();
        $logs = PostingLog::query()->with('content:id,title', 'campaign:id,name')->whereIn('id', $logIds)->get()->keyBy('id');
        $campaignIds = collect($jobs->items())->map(fn ($j) => self::describe($j)['campaign_id'])->filter()->unique();
        $campaigns = Campaign::withTrashed()->whereIn('id', $campaignIds)->pluck('name', 'id');

        $detail = $this->showDetail && $this->detailUuid
            ? DB::table(config('queue.failed.table', 'failed_jobs'))->where('uuid', $this->detailUuid)->first()
            : null;

        return view('livewire.failed-jobs.index', [
            'jobs' => $jobs,
            'logs' => $logs,
            'campaigns' => $campaigns,
            'detail' => $detail,
            'detailInfo' => $detail ? self::describe($detail) : null,
            'detailException' => $detail ? Str::limit(TelegramService::redact((string) $detail->exception), 6000) : null,
        ]);
    }
}
