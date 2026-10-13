<div>
    <x-page-header title="Failed Jobs" description="Queue jobs that crashed unexpectedly (Telegram errors are handled by the retry system and appear in Posting Logs instead).">
        <x-slot:actions>
            @if ($jobs->total() > 0)
                <x-confirm action="retryAll" class="btn btn-secondary" title="Retry all failed jobs?" :danger="false" message="Every failed job is pushed back onto the queue. Delivery jobs never post twice: an attempt that already finished is ignored." confirm-text="Retry all">
                    <x-icon name="refresh" class="size-4" /> Retry all
                </x-confirm>
                <x-confirm action="flush" class="btn btn-danger" title="Delete all failed jobs?" message="This permanently removes all failed job records." confirm-text="Delete all">
                    <x-icon name="trash" class="size-4" /> Delete all
                </x-confirm>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        @if ($jobs->isEmpty())
            <x-empty icon="check-circle" title="No failed jobs" description="All queue jobs completed or were handled by the retry system." />
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Failed at</th><th>Job</th><th class="hidden md:table-cell">Campaign / content</th><th>Error</th><th class="text-right">Actions</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($jobs as $job)
                            @php $info = \App\Livewire\FailedJobs\Index::describe($job); $log = $info['log_id'] ? ($logs[$info['log_id']] ?? null) : null; @endphp
                            <tr wire:key="fj-{{ $job->id }}">
                                <td class="whitespace-nowrap text-xs">{{ display_time(\Carbon\CarbonImmutable::parse($job->failed_at, 'UTC'), 'M j, g:i:s A') }}</td>
                                <td>
                                    <div class="text-sm font-medium">{{ $info['name'] }}</div>
                                    <div class="text-xs text-slate-400">queue: {{ $job->queue }}</div>
                                </td>
                                <td class="hidden text-xs md:table-cell">
                                    @php $campaignId = $info['campaign_id'] ?? $log?->campaign_id; @endphp
                                    @if ($campaignId)
                                        <a href="{{ route('campaigns.show', $campaignId) }}" wire:navigate class="text-brand-600 hover:underline">{{ $campaigns[$campaignId] ?? $log?->campaign?->name ?? 'Campaign #'.$campaignId }}</a>
                                    @endif
                                    @if ($log?->content) <div class="text-slate-500">{{ $log->content->title }}</div> @endif
                                </td>
                                <td class="max-w-md text-xs text-red-600 dark:text-red-400">{{ str(\App\Services\Telegram\TelegramService::redact(strtok($job->exception, "\n")))->limit(160) }}</td>
                                <td>
                                    <div class="flex justify-end gap-1">
                                        <button type="button" wire:click="show('{{ $job->uuid }}')" class="btn-icon" title="View"><x-icon name="eye" class="size-4" /></button>
                                        <button type="button" wire:click="retry('{{ $job->uuid }}')" class="btn-icon text-brand-600" title="Retry"><x-icon name="refresh" class="size-4" /></button>
                                        <x-confirm action="delete('{{ $job->uuid }}')" class="btn-icon hover:text-red-600" title="Delete failed job?" confirm-text="Delete"><x-icon name="trash" class="size-4" /></x-confirm>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($jobs->hasPages())
                <div class="border-t border-slate-200 px-4 py-3 dark:border-slate-800">{{ $jobs->links() }}</div>
            @endif
        @endif
    </div>

    <x-modal wire:model="showDetail" title="Failed job" max-width="4xl">
        @if ($detail)
            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-slate-500">Job</dt><dd>{{ $detailInfo['name'] }}</dd>
                <dt class="text-slate-500">UUID</dt><dd class="font-mono text-xs">{{ $detail->uuid }}</dd>
                <dt class="text-slate-500">Queue / connection</dt><dd>{{ $detail->queue }} / {{ $detail->connection }}</dd>
                <dt class="text-slate-500">Failed at</dt><dd>{{ display_time(\Carbon\CarbonImmutable::parse($detail->failed_at, 'UTC'), 'M j, Y g:i:s A') }}</dd>
            </dl>
            <div class="label mt-4">Exception</div>
            <pre class="max-h-96 overflow-auto rounded-lg bg-slate-900 p-3 font-mono text-xs text-slate-100">{{ $detailException }}</pre>
        @endif
        <x-slot:footer>
            @if ($detail)
                <button type="button" wire:click="delete('{{ $detail->uuid }}')" class="btn btn-secondary">Delete</button>
                <button type="button" wire:click="retry('{{ $detail->uuid }}')" class="btn btn-primary">Retry job</button>
            @endif
        </x-slot:footer>
    </x-modal>
</div>
