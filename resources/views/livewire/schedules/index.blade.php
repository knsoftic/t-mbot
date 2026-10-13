<div wire:poll.30s.visible>
    <x-page-header title="Schedules" description="When each campaign will post next. A single scheduler checks every 10 seconds for due campaigns — no per-campaign cron jobs." />

    <div class="card">
        <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row dark:border-slate-800">
            <div class="inline-flex rounded-lg bg-slate-100 p-1 dark:bg-slate-800">
                @foreach (['active' => 'Active', 'paused' => 'Paused', 'all' => 'All'] as $value => $label)
                    <button type="button" wire:click="$set('scope', '{{ $value }}')" @class([
                        'rounded-md px-3 py-1.5 text-xs font-medium',
                        'bg-white text-slate-900 shadow dark:bg-slate-700 dark:text-white' => $scope === $value,
                        'text-slate-500' => $scope !== $value,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
            <select wire:model.live="botFilter" class="input sm:w-56">
                <option value="">All bots</option>
                @foreach ($bots as $bot)
                    <option value="{{ $bot->id }}">{{ $bot->name }}</option>
                @endforeach
            </select>
        </div>

        @if ($campaigns->isEmpty())
            <x-empty icon="calendar" title="No scheduled campaigns" description="Activate a campaign to see its upcoming posts here." />
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th>Trigger & rules</th>
                            <th>Upcoming posts ({{ display_tz() }})</th>
                            <th class="hidden md:table-cell">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($campaigns as $campaign)
                            <tr wire:key="s-{{ $campaign->id }}">
                                <td>
                                    <a href="{{ route('campaigns.show', $campaign) }}" wire:navigate class="font-medium text-slate-900 hover:text-brand-600 dark:text-white">{{ $campaign->name }}</a>
                                    <div class="text-xs text-slate-500">{{ $campaign->bot?->name }} → {{ $campaign->destination?->name }} · {{ $campaign->pending_count }} pending</div>
                                </td>
                                <td class="text-xs">
                                    <div class="font-medium text-slate-700 dark:text-slate-200">{{ $campaign->trigger_type->shortLabel() }}</div>
                                    <div class="text-slate-500">{{ $campaign->scheduleSummary() }}</div>
                                    <div class="text-slate-400">{{ $campaign->timezone }}</div>
                                </td>
                                <td>
                                    @forelse ($upcoming[$campaign->id] ?? [] as $run)
                                        <div class="text-xs tabular-nums {{ $loop->first ? 'font-semibold text-slate-900 dark:text-white' : 'text-slate-500' }}">
                                            {{ display_time($run, 'D, M j · g:i A') }} @if ($loop->first)<span class="font-normal text-slate-400">({{ relative_time($run) }})</span>@endif
                                        </div>
                                    @empty
                                        <span class="text-xs text-slate-400">{{ $campaign->trigger_type === \App\Enums\TriggerType::Manual ? 'Run Now only' : '—' }}</span>
                                    @endforelse
                                </td>
                                <td class="hidden md:table-cell"><x-status :value="$campaign->status" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($campaigns->hasPages())
                <div class="border-t border-slate-200 px-4 py-3 dark:border-slate-800">{{ $campaigns->links() }}</div>
            @endif
        @endif
    </div>
</div>
