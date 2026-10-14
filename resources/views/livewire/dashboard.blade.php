<div wire:poll.20s.visible>
    <x-page-header title="Dashboard" description="Live overview of your bots, destinations, content and campaigns.">
        <x-slot:actions>
            @can('campaigns.manage')
                <a href="{{ route('campaigns.create') }}" wire:navigate class="btn btn-primary"><x-icon name="plus" class="size-4" /> New campaign</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($usage)
        <x-plan-usage :usage="$usage" compact class="mb-4" />
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Posts sent today" :value="number_format($stats['sent_today'])" icon="check-circle" color="green" :href="route('logs.index', ['status' => 'sent'])" />
        <x-stat label="Failed posts today" :value="number_format($stats['failed_today'])" icon="x-circle" color="red" :href="route('logs.index', ['status' => 'failed'])" />
        <x-stat label="Pending posts" :value="number_format($stats['pending'])" icon="clock" color="amber" hint="In active & paused campaigns" />
        <x-stat label="Active campaigns" :value="$stats['running']" icon="bolt" color="brand" :href="route('campaigns.index', ['status' => 'running'])" :hint="$stats['paused'].' paused'" />
    </div>

    <div class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-4 xl:grid-cols-7">
        @foreach ([
            ['Total bots', $stats['bots'], 'bot', route('bots.index')],
            ['Active bots', $stats['active_bots'], 'bot', route('bots.index', ['status' => 'active'])],
            ['Channels', $stats['channels'], 'megaphone', route('destinations.channels')],
            ['Groups', $stats['groups'], 'user-group', route('destinations.groups')],
            ['Content items', $stats['contents'], 'photo', route('contents.index')],
            ['Scheduled', $stats['scheduled'], 'calendar', route('campaigns.index', ['status' => 'scheduled'])],
            ['Completed', $stats['completed'], 'check', route('campaigns.index', ['status' => 'completed'])],
        ] as [$label, $value, $icon, $href])
            <a href="{{ $href }}" wire:navigate class="card p-4 transition hover:ring-brand-300 dark:hover:ring-brand-700">
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400"><x-icon :name="$icon" class="size-4" /> {{ $label }}</div>
                <div class="mt-1 text-xl font-semibold tabular-nums text-slate-900 dark:text-white">{{ number_format($value) }}</div>
            </a>
        @endforeach
    </div>

    @if ($attention->isNotEmpty())
        <div class="mt-6 rounded-xl bg-red-50 p-4 ring-1 ring-red-200 dark:bg-red-500/10 dark:ring-red-500/30">
            <div class="flex items-center gap-2 text-sm font-semibold text-red-800 dark:text-red-300"><x-icon name="warning" class="size-5" /> Campaigns needing attention</div>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($attention as $campaign)
                    <li><a href="{{ route('campaigns.show', $campaign) }}" wire:navigate class="font-medium text-red-700 underline-offset-2 hover:underline dark:text-red-300">{{ $campaign->name }}</a>
                        <span class="text-red-600/80 dark:text-red-300/70">— {{ str($campaign->last_error)->limit(120) }}</span></li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-5">
        <section class="card lg:col-span-3">
            <div class="card-header">
                <h2 class="card-title">Recent activity</h2>
                @can('logs.view')
                    <a href="{{ route('logs.index') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:text-brand-500">All logs →</a>
                @endcan
            </div>
            @if ($activity->isEmpty())
                <x-empty icon="list" title="No activity yet" description="Posting results and campaign events will appear here." />
            @else
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($activity as $event)
                        <li class="flex items-start gap-3 px-5 py-3">
                            <span @class([
                                'mt-0.5',
                                'text-emerald-500' => $event['tone'] === 'green',
                                'text-red-500' => $event['tone'] === 'red',
                                'text-amber-500' => $event['tone'] === 'amber',
                                'text-brand-500' => $event['tone'] === 'blue',
                            ])><x-icon :name="$event['icon']" class="size-5" /></span>
                            <div class="min-w-0 flex-1">
                                @if ($event['url'])
                                    <a href="{{ $event['url'] }}" wire:navigate class="text-sm text-slate-700 hover:text-brand-600 dark:text-slate-200">{{ $event['text'] }}</a>
                                @else
                                    <p class="text-sm text-slate-700 dark:text-slate-200">{{ $event['text'] }}</p>
                                @endif
                                <p class="text-xs text-slate-400" title="{{ display_time($event['at']) }}">{{ relative_time($event['at']) }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="card lg:col-span-2">
            <div class="card-header">
                <h2 class="card-title">Next scheduled posts</h2>
                <a href="{{ route('schedules.index') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:text-brand-500">Schedules →</a>
            </div>
            @if ($upcoming->isEmpty())
                <x-empty icon="calendar" title="Nothing scheduled" description="Activate a campaign to see its next post here." />
            @else
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($upcoming as $campaign)
                        <li>
                            <a href="{{ route('campaigns.show', $campaign) }}" wire:navigate class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                <div class="flex w-14 shrink-0 flex-col items-center rounded-lg bg-slate-100 py-1 dark:bg-slate-800">
                                    <span class="text-[10px] font-medium uppercase text-slate-500">{{ display_time($campaign->next_run_at, 'M j') }}</span>
                                    <span class="text-sm font-semibold tabular-nums text-slate-900 dark:text-white">{{ display_time($campaign->next_run_at, 'H:i') }}</span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $campaign->name }}</div>
                                    <div class="truncate text-xs text-slate-500">{{ $campaign->bot?->name }} → {{ $campaign->destination?->name }}</div>
                                </div>
                                <x-status :value="$campaign->status" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</div>
