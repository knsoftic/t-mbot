<div>
    <x-page-header title="Audit Logs" description="Who did what, when and from where. Secrets are never recorded." />

    <div class="card">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-6 dark:border-slate-800">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search description or IP…" class="input lg:col-span-2">
            <select wire:model.live="user" class="input">
                <option value="">All users</option>
                <option value="system">System / queue</option>
                @foreach ($users as $u) <option value="{{ $u->id }}">{{ $u->name }}</option> @endforeach
            </select>
            <select wire:model.live="area" class="input">
                <option value="">All areas</option>
                @foreach ($areas as $key => $label) <option value="{{ $key }}">{{ $label }}</option> @endforeach
            </select>
            <input type="date" wire:model.live="from" class="input" title="From">
            <input type="date" wire:model.live="to" class="input" title="To">
        </div>

        @if ($logs->isEmpty())
            <x-empty icon="shield" title="No audit entries" />
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Time</th><th>User</th><th>Action</th><th class="hidden md:table-cell">Record</th><th class="hidden lg:table-cell">IP address</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr wire:key="a-{{ $log->id }}">
                                <td class="whitespace-nowrap text-xs">{{ display_time($log->created_at, 'M j, g:i:s A') }}</td>
                                <td class="text-sm">{{ $log->user?->name ?? 'System' }}</td>
                                <td>
                                    <x-badge :color="str_starts_with($log->action, 'auth.') ? 'indigo' : (str_contains($log->action, 'deleted') || str_contains($log->action, 'failed') ? 'red' : 'slate')" class="font-mono">{{ $log->action }}</x-badge>
                                    <div class="mt-1 max-w-md truncate text-xs text-slate-500">{{ $log->description }}</div>
                                </td>
                                <td class="hidden text-xs text-slate-500 md:table-cell">{{ $log->entityLabel() }}</td>
                                <td class="hidden font-mono text-xs text-slate-500 lg:table-cell">{{ $log->ip_address ?? '—' }}</td>
                                <td class="text-right">
                                    @if ($log->metadata)
                                        <button type="button" wire:click="show({{ $log->id }})" class="btn-icon" title="Details"><x-icon name="eye" class="size-4" /></button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-3 dark:border-slate-800">{{ $logs->links() }}</div>
        @endif
    </div>

    <x-modal wire:model="showDetail" title="Audit entry" max-width="2xl">
        @if ($detail)
            <p class="text-sm"><strong>{{ $detail->action }}</strong> — {{ $detail->description }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ $detail->user?->name ?? 'System' }} · {{ display_time($detail->created_at, 'M j, Y g:i:s A') }} · {{ $detail->ip_address }}</p>
            @if ($detail->user_agent) <p class="mt-1 truncate text-xs text-slate-400">{{ $detail->user_agent }}</p> @endif
            <pre class="mt-3 max-h-96 overflow-auto rounded-lg bg-slate-900 p-3 font-mono text-xs text-slate-100">{{ json_encode($detail->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        @endif
    </x-modal>
</div>
