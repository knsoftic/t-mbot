@php
    use App\Enums\CampaignStatus as CS;
    use App\Enums\ItemStatus as IS;
    $status = $campaign->status;
@endphp
<div @if ($poll) wire:poll.4s.visible @endif>
    <x-page-header :title="$campaign->name" :description="$campaign->description">
        <x-slot:breadcrumb>
            <a href="{{ route('campaigns.index') }}" wire:navigate class="hover:text-brand-600">Campaigns</a>
            <x-icon name="chevron-right" class="size-3" /> <span>Details</span>
        </x-slot:breadcrumb>
        <x-slot:actions>
            @can('campaigns.operate')
                @if ($status === CS::Draft)
                    <button type="button" wire:click="activate" class="btn btn-success"><x-icon name="bolt" class="size-4" /> Activate</button>
                @endif
                @if ($status->allowsManualRun())
                    <button type="button" wire:click="runNow" wire:loading.attr="disabled" wire:target="runNow" class="btn btn-primary">
                        <span wire:loading wire:target="runNow"><x-spinner /></span><x-icon name="play" class="size-4" wire:loading.remove wire:target="runNow" /> Run now
                    </button>
                @endif
                @if ($status->isActive())
                    <button type="button" wire:click="pause" class="btn btn-warning"><x-icon name="pause" class="size-4" /> Pause</button>
                @endif
                @if (in_array($status, [CS::Paused, CS::Failed], true))
                    <button type="button" wire:click="resume" class="btn btn-success"><x-icon name="play" class="size-4" /> Resume</button>
                @endif
                @if (in_array($status, [CS::Scheduled, CS::Running, CS::Paused, CS::Failed], true))
                    <x-confirm action="stop" class="btn btn-secondary" title="Stop campaign?" message="No further posts will be sent. You can restart it later from item #1." confirm-text="Stop">
                        <x-icon name="stop" class="size-4" /> Stop
                    </x-confirm>
                @endif
                @if ($status !== CS::Draft)
                    <x-confirm action="restart" class="btn btn-secondary" title="Restart from item #1?" :danger="false"
                               message="All items are reset to pending and the sequence starts again from item #1. Posting history is preserved." confirm-text="Restart">
                        <x-icon name="refresh" class="size-4" /> Restart
                    </x-confirm>
                @endif
            @endcan
            @can('campaigns.manage')
                @if ($campaign->isEditable())
                    <a href="{{ route('campaigns.edit', $campaign) }}" wire:navigate class="btn btn-secondary"><x-icon name="pencil" class="size-4" /> Edit</a>
                @endif
            @endcan
            @can('logs.view')
                <a href="{{ route('logs.index', ['campaign_id' => $campaign->id]) }}" wire:navigate class="btn btn-secondary"><x-icon name="list" class="size-4" /> Logs</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($campaign->test_mode)
        <div class="mb-4 flex items-center gap-2 rounded-xl bg-purple-50 px-4 py-3 text-sm text-purple-900 ring-1 ring-purple-200 dark:bg-purple-500/10 dark:text-purple-200 dark:ring-purple-500/30">
            <x-icon name="beaker" class="size-5" /> <span><strong>Test mode is on.</strong> Posts go to “{{ $campaign->testDestination?->name }}” instead of “{{ $campaign->destination?->name }}”.</span>
        </div>
    @endif
    @if ($status === CS::Failed && $campaign->last_error)
        <div class="mb-4 flex items-start gap-2 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200 dark:bg-red-500/10 dark:text-red-200 dark:ring-red-500/30">
            <x-icon name="warning" class="size-5" /> <span>{{ $campaign->last_error }}</span>
        </div>
    @endif
    @if ($problems)
        <div class="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30">
            <div class="flex items-center gap-2 font-semibold"><x-icon name="warning" class="size-5" /> Fix before activating / resuming:</div>
            <ul class="ml-7 mt-1 list-disc space-y-0.5">@foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- Overview --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-4">
        <div class="card p-5 lg:col-span-2">
            <div class="flex items-center justify-between">
                <x-status :value="$status" class="text-sm" />
                <span class="text-xs text-slate-500">Cycle {{ $campaign->cycle }} @if ($campaign->cycles_completed) · {{ $campaign->cycles_completed }} completed @endif</span>
            </div>
            <div class="mt-4 flex items-end justify-between">
                <div>
                    <div class="text-xs text-slate-500">Progress</div>
                    <div class="text-3xl font-semibold tabular-nums text-slate-900 dark:text-white">{{ $progress['done'] }} <span class="text-lg text-slate-400">/ {{ $progress['total'] }}</span></div>
                </div>
                <div class="text-sm font-semibold tabular-nums text-slate-500">{{ $progress['percent'] }}%</div>
            </div>
            <x-progress :percent="$progress['percent']" class="mt-2 h-2.5" :color="$progress['failed'] ? 'amber' : 'brand'" />
            <div class="mt-4 grid grid-cols-4 gap-2 text-center">
                @foreach ([['Sent', $progress['sent'], 'text-emerald-600'], ['Pending', $progress['pending'] + $progress['processing'], 'text-slate-600 dark:text-slate-300'], ['Failed', $progress['failed'], 'text-red-600'], ['Skipped', $progress['skipped'], 'text-amber-600']] as [$label, $count, $color])
                    <div class="rounded-lg bg-slate-50 py-2 dark:bg-slate-800/50">
                        <div class="text-lg font-semibold tabular-nums {{ $color }}">{{ $count }}</div>
                        <div class="text-[11px] text-slate-500">{{ $label }}</div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="card space-y-3 p-5 text-sm">
            <div>
                <div class="text-xs text-slate-500">Last post</div>
                <div class="font-medium text-slate-900 dark:text-white">{{ $lastLog ? display_time($lastLog->sent_at, 'M j, g:i A') : '—' }}</div>
                @if ($lastLog) <div class="text-xs text-slate-400">{{ relative_time($lastLog->sent_at) }}</div> @endif
            </div>
            <div>
                <div class="text-xs text-slate-500">Next post</div>
                <div class="font-medium text-slate-900 dark:text-white">
                    @if ($campaign->next_run_at) {{ display_time($campaign->next_run_at, 'M j, g:i A') }}
                    @elseif ($campaign->trigger_type === \App\Enums\TriggerType::Manual && $status->allowsManualRun()) On Run Now
                    @else — @endif
                </div>
                @if ($campaign->next_run_at) <div class="text-xs text-slate-400">{{ relative_time($campaign->next_run_at) }}</div> @endif
            </div>
        </div>
        <div class="card space-y-3 p-5 text-sm">
            <div>
                <div class="text-xs text-slate-500">Next content</div>
                @if ($nextItem)
                    <div class="flex items-center gap-2 font-medium text-slate-900 dark:text-white">
                        <x-content-icon :type="$nextItem->content?->type" class="size-7" />
                        <span class="truncate">#{{ $nextItem->sort_order }} · {{ $nextItem->content?->title }}</span>
                    </div>
                    @if ($nextItem->isWaitingForRetry())
                        <div class="mt-1 text-xs text-amber-600">Retrying {{ relative_time($nextItem->next_attempt_at) }}</div>
                    @endif
                @else
                    <div class="font-medium text-slate-400">Nothing pending</div>
                @endif
            </div>
            <div>
                <div class="text-xs text-slate-500">Route</div>
                <div class="truncate font-medium text-slate-900 dark:text-white">{{ $campaign->bot?->name }} → {{ $campaign->destination?->name }}</div>
            </div>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        {{-- Sequence builder --}}
        <section class="card xl:col-span-2">
            <div class="card-header">
                <h2 class="card-title">Content sequence <span class="ml-1 text-xs font-normal text-slate-400">{{ $campaign->posting_order->label() }} order</span></h2>
                @can('campaigns.manage')
                    <button type="button" wire:click="openAdd" class="btn btn-secondary btn-sm"><x-icon name="plus" class="size-4" /> Add content</button>
                @endcan
            </div>
            @if ($items->isEmpty())
                <x-empty icon="list" title="The sequence is empty" description="Add content items to start posting." />
            @else
                <ul @can('campaigns.manage') wire:sort="reorder" @endcan class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($items as $item)
                        <li wire:sort:item="{{ $item->id }}" wire:key="item-{{ $item->id }}" @class([
                            'flex items-center gap-3 px-4 py-2.5',
                            'bg-brand-50/60 dark:bg-brand-500/10' => $nextItem && $nextItem->id === $item->id,
                        ])>
                            @can('campaigns.manage')
                                <span wire:sort:handle class="cursor-grab text-slate-300 hover:text-slate-500 active:cursor-grabbing" title="Drag to reorder"><x-icon name="grip" class="size-5" /></span>
                            @endcan
                            <span class="w-7 text-center text-sm font-semibold tabular-nums text-slate-400">{{ $item->sort_order }}</span>
                            <x-content-icon :type="$item->content?->type" class="size-8" />
                            <div class="min-w-0 flex-1">
                                <button type="button" wire:click="preview({{ $item->content_id }})" class="block max-w-full truncate text-left text-sm font-medium text-slate-900 hover:text-brand-600 dark:text-white">
                                    {{ $item->content?->title ?? 'Deleted content' }}
                                    @if ($item->content && ! $item->content->isActive()) <x-badge color="amber" class="ml-1">inactive</x-badge> @endif
                                </button>
                                <div class="truncate text-xs text-slate-500">
                                    @if ($item->status === IS::Sent)
                                        Sent {{ display_time($item->sent_at, 'M j, g:i A') }} · message #{{ $item->telegram_message_id }}
                                    @elseif ($item->isWaitingForRetry())
                                        <span class="text-amber-600">Retry {{ relative_time($item->next_attempt_at) }} · attempt {{ $item->attempts }}</span>
                                    @elseif ($item->error_message)
                                        <span class="text-red-600 dark:text-red-400" title="{{ $item->error_message }}">{{ str($item->error_message)->limit(110) }}</span>
                                    @elseif ($item->scheduled_at)
                                        Scheduled {{ display_time($item->scheduled_at, 'M j, g:i A', $campaign->timezone) }}
                                    @else
                                        {{ $item->content?->type->label() }}
                                    @endif
                                </div>
                            </div>
                            @if ($item->status === IS::Processing)
                                <span class="inline-flex items-center gap-1 text-xs font-medium text-brand-600"><x-spinner /> Sending</span>
                            @else
                                <x-status :value="$item->status" :dot="false" />
                            @endif
                            <div class="flex items-center gap-0.5" wire:sort:ignore>
                                @can('campaigns.operate')
                                    @if ($item->status === IS::Pending)
                                        <button type="button" wire:click="skip({{ $item->id }})" class="btn-icon" title="Skip"><x-icon name="forward" class="size-4" /></button>
                                    @elseif (in_array($item->status, [IS::Failed, IS::Skipped], true))
                                        <button type="button" wire:click="retry({{ $item->id }})" class="btn-icon text-brand-600" title="Retry / post again"><x-icon name="refresh" class="size-4" /></button>
                                    @endif
                                @endcan
                                @can('campaigns.manage')
                                    <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                                        <button type="button" class="btn-icon" x-on:click="open = !open" title="More"><x-icon name="dots" class="size-4" /></button>
                                        <div x-show="open" x-cloak x-transition class="dropdown w-44">
                                            <button type="button" wire:click="move({{ $item->id }}, -1)" class="dropdown-item" @disabled($loop->first)><x-icon name="chevron-up" class="size-4" /> Move up</button>
                                            <button type="button" wire:click="move({{ $item->id }}, 1)" class="dropdown-item" @disabled($loop->last)><x-icon name="chevron-down" class="size-4" /> Move down</button>
                                            <button type="button" wire:click="duplicateItem({{ $item->id }})" class="dropdown-item"><x-icon name="copy" class="size-4" /> Duplicate</button>
                                            <button type="button" wire:click="preview({{ $item->content_id }})" class="dropdown-item"><x-icon name="eye" class="size-4" /> Preview</button>
                                            @if ($item->status !== IS::Processing)
                                                <x-confirm action="removeItem({{ $item->id }})" class="dropdown-item text-red-600" title="Remove item?" message="Remove this item from the sequence? Its posting history is kept." confirm-text="Remove">
                                                    <x-icon name="trash" class="size-4" /> Remove
                                                </x-confirm>
                                            @endif
                                        </div>
                                    </div>
                                @endcan
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Configuration + recent logs --}}
        <div class="space-y-6">
            <section class="card">
                <div class="card-header"><h2 class="card-title">Configuration</h2></div>
                <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    @foreach ([
                        'Bot' => ($campaign->bot?->name ?? '—').($campaign->bot?->username ? ' (@'.$campaign->bot->username.')' : ''),
                        'Destination' => ($campaign->destination?->name ?? '—').' · '.($campaign->destination?->chat_id ?? ''),
                        'Trigger' => $campaign->trigger_type->label(),
                        'Schedule' => $campaign->scheduleSummary(),
                        'Timezone' => $campaign->timezone,
                        'Start' => $campaign->start_at ? display_time($campaign->start_at, 'M j, Y g:i A', $campaign->timezone) : 'On activation',
                        'End' => $campaign->end_at ? display_time($campaign->end_at, 'M j, Y g:i A', $campaign->timezone) : 'No end date',
                        'Repeat' => $campaign->repeat_mode->label().($campaign->repeat_limit ? " ({$campaign->repeat_limit}×)" : ''),
                        'On failure' => $campaign->failure_behavior->label(),
                        'Retries' => $campaign->max_retries.' × starting at '.$campaign->retry_delay_seconds.'s',
                        'Created by' => $campaign->creator?->name ?? '—',
                    ] as $label => $value)
                        <div class="flex justify-between gap-4 px-5 py-2.5">
                            <dt class="shrink-0 text-slate-500">{{ $label }}</dt>
                            <dd class="truncate text-right font-medium text-slate-800 dark:text-slate-200" title="{{ $value }}">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
                @can('campaigns.manage')
                    @if (in_array($status, [CS::Draft, CS::Cancelled, CS::Completed, CS::Failed, CS::Paused], true))
                        <div class="border-t border-slate-100 px-5 py-3 dark:border-slate-800">
                            <x-confirm action="delete" class="btn btn-ghost btn-sm text-red-600" title="Delete campaign?" message="The campaign is removed from the panel. Posting logs are kept." confirm-text="Delete">
                                <x-icon name="trash" class="size-4" /> Delete campaign
                            </x-confirm>
                        </div>
                    @endif
                @endcan
            </section>

            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">Recent posting activity</h2>
                    @can('logs.view')
                        <a href="{{ route('logs.index', ['campaign_id' => $campaign->id]) }}" wire:navigate class="text-xs font-medium text-brand-600">All →</a>
                    @endcan
                </div>
                @if ($recentLogs->isEmpty())
                    <p class="px-5 py-6 text-center text-sm text-slate-500">No posts yet.</p>
                @else
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($recentLogs as $log)
                            <li class="px-5 py-2.5 text-sm">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate font-medium text-slate-800 dark:text-slate-200">{{ $log->content?->title }}</span>
                                    <x-status :value="$log->status" :dot="false" />
                                </div>
                                <div class="text-xs text-slate-400">
                                    {{ display_time($log->sent_at ?? $log->created_at, 'M j, g:i:s A') }} · {{ $log->trigger->label() }} · attempt {{ $log->attempts }}
                                    @if ($log->is_test) · test @endif
                                </div>
                                @if ($log->error_message)
                                    <div class="mt-0.5 text-xs text-red-600 dark:text-red-400">{{ str($log->error_message)->limit(120) }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>

    {{-- Add content modal --}}
    <x-modal wire:model="showAdd" title="Add content to the sequence" max-width="2xl">
        <div class="relative">
            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <input type="search" wire:model.live.debounce.400ms="addSearch" placeholder="Search active content…" class="input pl-9">
        </div>
        <div class="mt-3 divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200 dark:divide-slate-800 dark:ring-slate-800">
            @if ($addContents)
                @forelse ($addContents as $content)
                    <label wire:key="add-{{ $content->id }}" class="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <input type="checkbox" wire:model.live="addSelected" value="{{ $content->id }}" class="checkbox">
                        <x-content-icon :type="$content->type" class="size-8" />
                        <span class="min-w-0 flex-1 truncate text-sm">{{ $content->title }}</span>
                        <span class="text-xs text-slate-400">{{ $content->type->label() }}</span>
                    </label>
                @empty
                    <p class="px-3 py-6 text-center text-sm text-slate-500">No active content found.</p>
                @endforelse
            @endif
        </div>
        @if ($addContents && $addContents->hasPages())
            <div class="mt-3">{{ $addContents->links(data: ['scrollTo' => false]) }}</div>
        @endif
        <x-slot:footer>
            <button type="button" class="btn btn-secondary" x-on:click="show = false">Cancel</button>
            <button type="button" wire:click="addSelectedContent" class="btn btn-primary" @disabled(empty($addSelected))>Add {{ count($addSelected) ?: '' }} selected</button>
        </x-slot:footer>
    </x-modal>

    <x-modal wire:model="showPreview" title="Preview" max-width="lg">
        @if ($previewContent)
            <x-tg-preview :content="$previewContent" :channel="$campaign->destination?->name ?? 'Channel'" />
        @endif
    </x-modal>
</div>
