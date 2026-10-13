<div @if ($poll) wire:poll.10s.visible @endif>
    <x-page-header title="Campaigns" description="Each campaign posts a content sequence through one bot to one destination, on its own schedule.">
        <x-slot:actions>
            @can('campaigns.manage')
                <a href="{{ route('campaigns.create') }}" wire:navigate class="btn btn-primary"><x-icon name="plus" class="size-4" /> New campaign</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 md:grid-cols-12 dark:border-slate-800">
            <div class="relative md:col-span-4">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search campaigns…" class="input pl-9">
            </div>
            <select wire:model.live="status" class="input md:col-span-2">
                <option value="">All statuses</option>
                @foreach (\App\Enums\CampaignStatus::options() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="botFilter" class="input md:col-span-3">
                <option value="">All bots</option>
                @foreach ($bots as $bot)
                    <option value="{{ $bot->id }}">{{ $bot->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="destinationFilter" class="input md:col-span-3">
                <option value="">All destinations</option>
                @foreach ($destinations as $destination)
                    <option value="{{ $destination->id }}">{{ $destination->name }}</option>
                @endforeach
            </select>
        </div>

        @if ($campaigns->isEmpty())
            <x-empty icon="bolt" title="No campaigns found" description="A campaign combines a bot, a destination, a content sequence and a schedule.">
                @can('campaigns.manage')
                    <a href="{{ route('campaigns.create') }}" wire:navigate class="btn btn-primary"><x-icon name="plus" class="size-4" /> Create campaign</a>
                @endcan
            </x-empty>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th class="hidden lg:table-cell">Schedule</th>
                            <th>Status</th>
                            <th class="hidden md:table-cell">Progress</th>
                            <th class="hidden xl:table-cell">Next post</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($campaigns as $campaign)
                            @php $percent = $campaign->items_total ? (int) floor($campaign->items_done * 100 / $campaign->items_total) : 0; @endphp
                            <tr wire:key="c-{{ $campaign->id }}">
                                <td>
                                    <a href="{{ route('campaigns.show', $campaign) }}" wire:navigate class="font-medium text-slate-900 hover:text-brand-600 dark:text-white">{{ $campaign->name }}</a>
                                    <div class="text-xs text-slate-500">
                                        {{ $campaign->bot?->name ?? '—' }} → {{ $campaign->destination?->name ?? '—' }}
                                        @if ($campaign->test_mode) <x-badge color="purple" class="ml-1">Test mode</x-badge> @endif
                                    </div>
                                </td>
                                <td class="hidden text-xs text-slate-600 lg:table-cell dark:text-slate-300">
                                    <div class="font-medium">{{ $campaign->trigger_type->shortLabel() }}</div>
                                    <div class="max-w-[14rem] truncate text-slate-400">{{ $campaign->scheduleSummary() }}</div>
                                </td>
                                <td>
                                    <x-status :value="$campaign->status" />
                                    @if ($campaign->items_failed)
                                        <div class="mt-1 text-[11px] text-red-500">{{ $campaign->items_failed }} failed</div>
                                    @endif
                                </td>
                                <td class="hidden w-40 md:table-cell">
                                    <div class="mb-1 text-xs tabular-nums text-slate-500">{{ $campaign->items_done }} / {{ $campaign->items_total }} @if ($campaign->cycle > 1)<span class="text-slate-400">· cycle {{ $campaign->cycle }}</span>@endif</div>
                                    <x-progress :percent="$percent" :color="$campaign->items_failed ? 'amber' : 'brand'" />
                                </td>
                                <td class="hidden text-xs xl:table-cell">
                                    @if ($campaign->next_run_at)
                                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ display_time($campaign->next_run_at, 'M j, g:i A') }}</div>
                                        <div class="text-slate-400">{{ relative_time($campaign->next_run_at) }}</div>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        @can('campaigns.operate')
                                            @if ($campaign->status->allowsManualRun())
                                                <button type="button" wire:click="runNow({{ $campaign->id }})" class="btn btn-secondary btn-sm" title="Post the next item now"><x-icon name="play" class="size-4" /> Run now</button>
                                            @endif
                                            @if ($campaign->status->isActive())
                                                <button type="button" wire:click="pause({{ $campaign->id }})" class="btn-icon" title="Pause"><x-icon name="pause" class="size-4" /></button>
                                            @elseif (in_array($campaign->status, [\App\Enums\CampaignStatus::Paused, \App\Enums\CampaignStatus::Failed], true))
                                                <button type="button" wire:click="resume({{ $campaign->id }})" class="btn-icon text-emerald-600" title="Resume"><x-icon name="play" class="size-4" /></button>
                                            @endif
                                        @endcan
                                        <a href="{{ route('campaigns.show', $campaign) }}" wire:navigate class="btn-icon" title="Open"><x-icon name="eye" class="size-4" /></a>
                                        @can('campaigns.manage')
                                            @if ($campaign->isEditable())
                                                <a href="{{ route('campaigns.edit', $campaign) }}" wire:navigate class="btn-icon" title="Edit"><x-icon name="pencil" class="size-4" /></a>
                                            @endif
                                        @endcan
                                    </div>
                                </td>
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
