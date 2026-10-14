<div>
    <x-page-header title="Posting Logs" description="Every delivery attempt, with its Telegram response. Logs are never deleted when a campaign is restarted." />

    <div class="card">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-7 dark:border-slate-800">
            <select wire:model.live="campaign_id" class="input lg:col-span-2">
                <option value="">All campaigns</option>
                @foreach ($campaigns as $c) <option value="{{ $c->id }}">{{ $c->name }}</option> @endforeach
            </select>
            <select wire:model.live="bot_id" class="input">
                <option value="">All bots</option>
                @foreach ($bots as $b) <option value="{{ $b->id }}">{{ $b->name }}</option> @endforeach
            </select>
            <select wire:model.live="destination_id" class="input">
                <option value="">All destinations</option>
                @foreach ($destinations as $d) <option value="{{ $d->id }}">{{ $d->name }}</option> @endforeach
            </select>
            <select wire:model.live="status" class="input">
                <option value="">All statuses</option>
                @foreach (\App\Enums\PostingStatus::options() as $value => $label) <option value="{{ $value }}">{{ $label }}</option> @endforeach
            </select>
            <input type="date" wire:model.live="from" class="input" title="From date">
            <div class="flex gap-2">
                <input type="date" wire:model.live="to" class="input" title="To date">
                <button type="button" wire:click="clearFilters" class="btn btn-ghost" @disabled(! $filtered) title="Clear filters"><x-icon name="x" class="size-4" /></button>
            </div>
        </div>

        <div class="relative">
            <div wire:loading.flex wire:target="campaign_id,bot_id,destination_id,status,from,to,clearFilters,gotoPage,nextPage,previousPage" class="absolute inset-0 z-10 items-center justify-center bg-white/60 dark:bg-slate-900/60">
                <x-spinner class="size-6 text-brand-600" />
            </div>
            @if ($logs->isEmpty())
                <x-empty icon="list" title="No posting logs" :description="$filtered ? 'No attempts match these filters.' : 'Posting attempts appear here once a campaign runs.'" />
            @else
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Content</th>
                                <th class="hidden lg:table-cell">Campaign</th>
                                <th class="hidden md:table-cell">Destination</th>
                                <th>Status</th>
                                <th class="hidden xl:table-cell">Attempt</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($logs as $log)
                                <tr wire:key="log-{{ $log->id }}">
                                    <td class="whitespace-nowrap text-xs">
                                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ display_time($log->sent_at ?? $log->created_at, 'M j, g:i:s A') }}</div>
                                        @if ($log->scheduled_at) <div class="text-slate-400">planned {{ display_time($log->scheduled_at, 'g:i A') }}</div> @endif
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <x-content-icon :type="$log->content?->type" class="size-7" />
                                            <span class="max-w-[14rem] truncate text-sm" title="{{ $log->message }}">{{ $log->content?->title ?? ($log->message ? str($log->message)->squish()->limit(60) : '—') }}</span>
                                        </div>
                                    </td>
                                    <td class="hidden text-sm lg:table-cell">
                                        @if ($log->campaign)
                                            <a href="{{ route('campaigns.show', $log->campaign_id) }}" wire:navigate class="hover:text-brand-600">{{ $log->campaign->name }}</a>
                                        @elseif ($log->trigger === \App\Enums\PostTrigger::QuickSend)
                                            <a href="{{ route('quick-send', ['target' => $log->quick_send_target_id]) }}" wire:navigate class="hover:text-brand-600">Quick Send</a>
                                        @elseif ($log->trigger === \App\Enums\PostTrigger::Welcome)
                                            <a href="{{ route('welcome.index') }}" wire:navigate class="hover:text-brand-600">Welcome DM</a>
                                        @elseif ($log->trigger === \App\Enums\PostTrigger::Invite)
                                            <a href="{{ route('member-imports.index') }}" wire:navigate class="hover:text-brand-600">Member invite</a>
                                        @else — @endif
                                    </td>
                                    <td class="hidden text-xs md:table-cell">
                                        {{ $log->destination?->name ?? '—' }}
                                        @if ($log->is_test) <x-badge color="purple">test</x-badge> @endif
                                        <div class="text-slate-400">via {{ $log->bot?->name ?? '—' }}</div>
                                    </td>
                                    <td>
                                        <x-status :value="$log->status" :dot="false" />
                                        @if ($log->error_message)
                                            <div class="mt-1 max-w-xs truncate text-xs text-red-600 dark:text-red-400" title="{{ $log->error_message }}">{{ $log->error_message }}</div>
                                        @elseif ($log->telegram_message_id)
                                            <div class="mt-1 text-xs text-slate-400">msg #{{ $log->telegram_message_id }}</div>
                                        @endif
                                    </td>
                                    <td class="hidden text-xs text-slate-500 xl:table-cell">#{{ $log->attempts }} · {{ $log->trigger->label() }} @if ($log->cycle > 1)· cycle {{ $log->cycle }}@endif</td>
                                    <td class="text-right"><button type="button" wire:click="show({{ $log->id }})" class="btn-icon" title="Details"><x-icon name="eye" class="size-4" /></button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-slate-200 px-4 py-3 dark:border-slate-800">{{ $logs->links() }}</div>
            @endif
        </div>
    </div>

    <x-modal wire:model="showDetail" :title="$detail ? 'Posting log #'.$detail->id : 'Posting log'" max-width="2xl">
        @if ($detail)
            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                @foreach ([
                    'Status' => $detail->status->label(),
                    'Trigger' => $detail->trigger->label(),
                    'Campaign' => $detail->campaign?->name ?? '—',
                    'Content' => $detail->content?->title ?? '—',
                    'Bot' => $detail->bot?->name ?? '—',
                    'Destination' => ($detail->destination?->name ?? '—').($detail->is_test ? ' (test mode)' : ''),
                    'Chat ID' => $detail->chat_id ?? '—',
                    'Telegram message ID' => $detail->telegram_message_id ?? '—',
                    'Scheduled' => display_time($detail->scheduled_at, 'M j, Y g:i:s A'),
                    'Sent' => display_time($detail->sent_at, 'M j, Y g:i:s A'),
                    'Attempt / cycle' => $detail->attempts.' / '.$detail->cycle,
                    'Duration' => $detail->duration_ms !== null ? $detail->duration_ms.' ms' : '—',
                    'Error code' => $detail->error_code ?? '—',
                ] as $label => $value)
                    <div class="flex justify-between gap-3 border-b border-slate-100 py-1.5 dark:border-slate-800">
                        <dt class="text-slate-500">{{ $label }}</dt><dd class="text-right font-medium">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
            @if ($detail->message)
                <div class="mt-4">
                    <div class="label">Message</div>
                    <div class="whitespace-pre-line break-words rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800/60">{{ $detail->message }}</div>
                </div>
            @endif
            @if ($detail->error_message)
                <div class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-200">{{ $detail->error_message }}</div>
            @endif
            <div class="mt-4">
                <div class="label">Telegram response</div>
                <pre class="max-h-72 overflow-auto rounded-lg bg-slate-900 p-3 font-mono text-xs text-slate-100">{{ $detail->response_data ? json_encode($detail->response_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'No response stored.' }}</pre>
            </div>
        @endif
    </x-modal>
</div>
