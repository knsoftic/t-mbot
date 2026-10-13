<div wire:poll.30s.visible>
    <x-page-header title="System Health" description="Live status of the services the posting engine depends on.">
        <x-slot:actions>
            @can('campaigns.operate')
                <x-confirm action="recover" class="btn btn-secondary" title="Run recovery now?" :danger="false" confirm-text="Run recovery"
                           message="Finds items stuck in “processing” after a crash. Attempts that never reached Telegram are re-queued; attempts that may have been delivered are marked failed (never re-sent automatically). This also runs every minute via the scheduler.">
                    <x-icon name="refresh" class="size-4" /> Run recovery
                </x-confirm>
            @endcan
            <button type="button" wire:click="refresh" class="btn btn-primary" wire:loading.attr="disabled"><span wire:loading wire:target="refresh"><x-spinner /></span> Re-check</button>
        </x-slot:actions>
    </x-page-header>

    <div @class([
        'mb-6 flex items-center gap-3 rounded-xl p-4 ring-1',
        'bg-emerald-50 text-emerald-900 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/30' => $overall === 'healthy',
        'bg-amber-50 text-amber-900 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30' => $overall === 'warning',
        'bg-red-50 text-red-900 ring-red-200 dark:bg-red-500/10 dark:text-red-200 dark:ring-red-500/30' => $overall === 'failed',
    ])>
        <x-icon :name="$overall === 'healthy' ? 'check-circle' : 'warning'" class="size-6" />
        <span class="font-semibold">{{ ['healthy' => 'All systems operational', 'warning' => 'Operational with warnings', 'failed' => 'Some services need attention'][$overall] }}</span>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($checks as $check)
            <div class="card p-5" wire:key="h-{{ $check['key'] }}">
                <div class="flex items-center justify-between">
                    <div class="font-semibold text-slate-900 dark:text-white">{{ $check['label'] }}</div>
                    <x-badge :color="['healthy' => 'green', 'warning' => 'amber', 'failed' => 'red'][$check['status']]" dot>{{ ['healthy' => 'Healthy', 'warning' => 'Warning', 'failed' => 'Failed'][$check['status']] }}</x-badge>
                </div>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $check['message'] }}</p>
            </div>
        @endforeach
    </div>

    <section class="card mt-6">
        <div class="card-header"><h2 class="card-title">Environment</h2></div>
        <dl class="grid gap-x-6 sm:grid-cols-2">
            @foreach ($environment as $label => $value)
                <div class="flex justify-between border-b border-slate-100 px-5 py-2.5 text-sm dark:border-slate-800">
                    <dt class="text-slate-500">{{ $label }}</dt>
                    <dd class="font-mono text-xs {{ str_contains($value, 'ON') ? 'text-amber-600' : '' }}">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
</div>
