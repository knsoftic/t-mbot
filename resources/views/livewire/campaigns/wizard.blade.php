<div>
    <x-page-header :title="$existing ? 'Edit campaign' : 'New campaign'" :description="$existing ? $existing->name : 'Set up a campaign in a few guided steps. Nothing is posted until you activate it.'">
        <x-slot:breadcrumb>
            <a href="{{ route('campaigns.index') }}" wire:navigate class="hover:text-brand-600">Campaigns</a>
            <x-icon name="chevron-right" class="size-3" /> <span>{{ $existing ? 'Edit' : 'New' }}</span>
        </x-slot:breadcrumb>
    </x-page-header>

    {{-- Stepper --}}
    <nav class="card mb-6 overflow-x-auto p-3" aria-label="Progress">
        <ol class="flex min-w-max items-center gap-1">
            @foreach ($steps as $number => $label)
                <li class="flex items-center gap-1">
                    <button type="button" wire:click="goTo({{ $number }})" @disabled($number > $maxStep) @class([
                        'flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-xs font-medium transition',
                        'bg-brand-600 text-white shadow-sm' => $number === $step,
                        'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800' => $number !== $step && $number <= $maxStep,
                        'cursor-not-allowed text-slate-400' => $number > $maxStep,
                    ])>
                        <span @class([
                            'flex size-5 items-center justify-center rounded-full text-[11px]',
                            'bg-white/25' => $number === $step,
                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' => $number < $step,
                            'bg-slate-200 dark:bg-slate-700' => $number > $step,
                        ])>
                            @if ($number < $step) <x-icon name="check" class="size-3" /> @else {{ $number }} @endif
                        </span>
                        {{ $label }}
                    </button>
                    @if (! $loop->last) <x-icon name="chevron-right" class="size-3 text-slate-300" /> @endif
                </li>
            @endforeach
        </ol>
    </nav>

    <div class="card">
        <div class="card-header">
            <div>
                <div class="text-xs font-medium text-slate-400">Step {{ $step }} of 10</div>
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ $steps[$step] }}</h2>
            </div>
            <div wire:loading class="text-brand-600"><x-spinner class="size-5" /></div>
        </div>

        <div class="p-5">
            {{-- STEP 1: info --}}
            @if ($step === 1)
                <div class="grid max-w-2xl gap-4">
                    <x-field label="Campaign name" for="name" required>
                        <input id="name" type="text" wire:model="name" class="input" maxlength="150" placeholder="e.g. Daily Videos" autofocus>
                    </x-field>
                    <x-field label="Description" for="description">
                        <textarea id="description" wire:model="description" rows="3" class="input" maxlength="2000" placeholder="What is this campaign for?"></textarea>
                    </x-field>
                </div>
            @endif

            {{-- STEP 2: bot --}}
            @if ($step === 2)
                @if ($bots->isEmpty())
                    <x-empty icon="bot" title="No bots yet" description="Add a bot before creating a campaign.">
                        <a href="{{ route('bots.index') }}" wire:navigate class="btn btn-primary">Add a bot</a>
                    </x-empty>
                @else
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($bots as $bot)
                            <label wire:key="bot-{{ $bot->id }}" @class([
                                'flex cursor-pointer items-start gap-3 rounded-xl p-4 ring-1 transition',
                                'bg-brand-50 ring-2 ring-brand-500 dark:bg-brand-500/10' => (string) $bot->id === $bot_id,
                                'ring-slate-200 hover:bg-slate-50 dark:ring-slate-700 dark:hover:bg-slate-800/50' => (string) $bot->id !== $bot_id,
                            ])>
                                <input type="radio" wire:model.live="bot_id" value="{{ $bot->id }}" class="mt-1">
                                <div class="min-w-0">
                                    <div class="font-medium text-slate-900 dark:text-white">{{ $bot->name }}</div>
                                    <div class="text-xs text-slate-500">{{ $bot->displayUsername() }} · {{ $bot->destinations_count }} destination(s)</div>
                                    <div class="mt-2 flex gap-1"><x-status :value="$bot->status" /> <x-status :value="$bot->connection_status" :dot="false" /></div>
                                </div>
                            </label>
                        @endforeach
                    </div>
                    @error('bot_id') <p class="error">{{ $message }}</p> @enderror
                @endif
            @endif

            {{-- STEP 3: destination + test mode --}}
            @if ($step === 3)
                @if ($botDestinations->isEmpty())
                    <x-empty icon="megaphone" title="This bot has no destinations" description="Connect a channel or group to {{ $selectedBot?->name }} first.">
                        <a href="{{ route('destinations.channels') }}" wire:navigate class="btn btn-primary">Add a channel</a>
                    </x-empty>
                @else
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($botDestinations as $destination)
                            <label wire:key="dest-{{ $destination->id }}" @class([
                                'flex cursor-pointer items-start gap-3 rounded-xl p-4 ring-1 transition',
                                'bg-brand-50 ring-2 ring-brand-500 dark:bg-brand-500/10' => (string) $destination->id === $destination_id,
                                'ring-slate-200 hover:bg-slate-50 dark:ring-slate-700 dark:hover:bg-slate-800/50' => (string) $destination->id !== $destination_id,
                            ])>
                                <input type="radio" wire:model.live="destination_id" value="{{ $destination->id }}" class="mt-1">
                                <div class="min-w-0">
                                    <div class="font-medium text-slate-900 dark:text-white">{{ $destination->name }}</div>
                                    <div class="truncate font-mono text-xs text-slate-500">{{ $destination->chat_id }}</div>
                                    <div class="mt-2 flex flex-wrap gap-1"><x-status :value="$destination->type" :dot="false" /> <x-status :value="$destination->connection_status" :dot="false" /></div>
                                </div>
                            </label>
                        @endforeach
                    </div>
                    @error('destination_id') <p class="error">{{ $message }}</p> @enderror

                    <div class="mt-6 rounded-xl bg-purple-50 p-4 ring-1 ring-purple-200 dark:bg-purple-500/10 dark:ring-purple-500/30">
                        <label class="flex items-center gap-2 text-sm font-semibold text-purple-900 dark:text-purple-200">
                            <input type="checkbox" wire:model.live="test_mode" class="checkbox"> <x-icon name="beaker" class="size-4" /> Test mode
                        </label>
                        <p class="mt-1 text-xs text-purple-800/80 dark:text-purple-200/80">While on, posts go to a test chat instead of the destination, so you can check content, order, captions and media safely.</p>
                        @if ($test_mode)
                            <div class="mt-3 max-w-md">
                                <select wire:model="test_destination_id" class="input">
                                    <option value="">Select the test chat…</option>
                                    @foreach ($botDestinations as $destination)
                                        <option value="{{ $destination->id }}">{{ $destination->name }}{{ $destination->is_test_chat ? ' (test chat)' : '' }}</option>
                                    @endforeach
                                </select>
                                @error('test_destination_id') <p class="error">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </div>
                @endif
            @endif

            {{-- STEP 4: select content --}}
            @if ($step === 4)
                <div class="grid gap-6 lg:grid-cols-5">
                    <div class="lg:col-span-3">
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <div class="relative flex-1">
                                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                                <input type="search" wire:model.live.debounce.400ms="contentSearch" placeholder="Search active content…" class="input pl-9">
                            </div>
                            <select wire:model.live="contentType" class="input sm:w-40">
                                <option value="">All types</option>
                                @foreach ($contentTypes as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mt-3 divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200 dark:divide-slate-800 dark:ring-slate-800">
                            @forelse ($contents as $content)
                                <div wire:key="pick-{{ $content->id }}" class="flex items-center gap-3 px-3 py-2.5">
                                    <x-content-icon :type="$content->type" />
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $content->title }}</div>
                                        <div class="truncate text-xs text-slate-500">{{ $content->type->label() }} · {{ str($content->body())->limit(60) ?: 'No caption' }}</div>
                                    </div>
                                    <button type="button" wire:click="addContent({{ $content->id }})" class="btn btn-secondary btn-sm"><x-icon name="plus" class="size-4" /> Add</button>
                                </div>
                            @empty
                                <x-empty icon="photo" title="No active content found" description="Create content in the library first.">
                                    <a href="{{ route('contents.create') }}" target="_blank" class="btn btn-secondary">Open content editor</a>
                                </x-empty>
                            @endforelse
                        </div>
                        @if ($contents && $contents->isNotEmpty())
                            <div class="mt-3 flex items-center justify-between gap-2">
                                <button type="button" wire:click="addAllVisible" class="btn btn-ghost btn-sm">Add all on this page</button>
                                <div>{{ $contents->links(data: ['scrollTo' => false]) }}</div>
                            </div>
                        @endif
                    </div>
                    <div class="lg:col-span-2">
                        <div class="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700">
                            <div class="text-sm font-semibold text-slate-900 dark:text-white">Selected: {{ count($items) }} item(s)</div>
                            <ol class="mt-2 max-h-80 space-y-1 overflow-y-auto text-sm">
                                @foreach ($items as $i => $item)
                                    <li wire:key="sel-{{ $item['key'] }}" class="flex items-center gap-2">
                                        <span class="w-6 text-right text-xs tabular-nums text-slate-400">{{ $i + 1 }}.</span>
                                        <span class="flex-1 truncate">{{ $item['title'] }}</span>
                                        <button type="button" wire:click="removeItem('{{ $item['key'] }}')" class="btn-icon" title="Remove"><x-icon name="x" class="size-3.5" /></button>
                                    </li>
                                @endforeach
                            </ol>
                            @error('items') <p class="error">{{ $message }}</p> @enderror
                            <p class="mt-3 text-xs text-slate-500">You can arrange the order in the next step.</p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- STEP 5: arrange --}}
            @if ($step === 5)
                <p class="mb-3 text-sm text-slate-500">Drag items by the handle (or use the arrows). In sequential mode they are posted exactly in this order.</p>
                <ul wire:sort="reorder" class="space-y-2">
                    @foreach ($items as $i => $item)
                        <li wire:sort:item="{{ $item['key'] }}" wire:key="arr-{{ $item['key'] }}" class="flex items-center gap-3 rounded-lg bg-white p-2.5 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                            <span wire:sort:handle class="cursor-grab text-slate-400 hover:text-slate-600 active:cursor-grabbing" title="Drag to reorder"><x-icon name="grip" class="size-5" /></span>
                            <span class="w-7 text-center text-sm font-semibold tabular-nums text-slate-500">{{ $i + 1 }}</span>
                            <x-content-icon :type="$item['type']" />
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-900 dark:text-white">{{ $item['title'] }}</span>
                            <div class="flex items-center gap-0.5" wire:sort:ignore>
                                <button type="button" wire:click="moveItem('{{ $item['key'] }}', -1)" class="btn-icon" title="Move up" @disabled($i === 0)><x-icon name="chevron-up" class="size-4" /></button>
                                <button type="button" wire:click="moveItem('{{ $item['key'] }}', 1)" class="btn-icon" title="Move down" @disabled($loop->last)><x-icon name="chevron-down" class="size-4" /></button>
                                <button type="button" wire:click="duplicateItem('{{ $item['key'] }}')" class="btn-icon" title="Duplicate"><x-icon name="copy" class="size-4" /></button>
                                <button type="button" wire:click="removeItem('{{ $item['key'] }}')" class="btn-icon hover:text-red-600" title="Remove"><x-icon name="trash" class="size-4" /></button>
                            </div>
                        </li>
                    @endforeach
                </ul>
                @error('items') <p class="error">{{ $message }}</p> @enderror
            @endif

            {{-- STEP 6: trigger --}}
            @if ($step === 6)
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach (\App\Enums\TriggerType::cases() as $case)
                        <label wire:key="trg-{{ $case->value }}" @class([
                            'flex cursor-pointer gap-3 rounded-xl p-4 ring-1 transition',
                            'bg-brand-50 ring-2 ring-brand-500 dark:bg-brand-500/10' => $trigger_type === $case->value,
                            'ring-slate-200 hover:bg-slate-50 dark:ring-slate-700 dark:hover:bg-slate-800/50' => $trigger_type !== $case->value,
                        ])>
                            <input type="radio" wire:model.live="trigger_type" value="{{ $case->value }}" class="mt-1">
                            <div>
                                <div class="text-sm font-semibold text-slate-900 dark:text-white">{{ $case->shortLabel() }}</div>
                                <div class="mt-0.5 text-xs text-slate-500">{{ $case->description() }}</div>
                            </div>
                        </label>
                    @endforeach
                </div>
                @if ($trigger_type !== 'scheduled')
                    <div class="mt-6 max-w-md">
                        <span class="label">Posting order</span>
                        <div class="flex gap-4 text-sm">
                            <label class="flex items-center gap-2"><input type="radio" wire:model="posting_order" value="sequential"> Sequential (default)</label>
                            <label class="flex items-center gap-2"><input type="radio" wire:model="posting_order" value="random"> Random</label>
                        </div>
                        <p class="hint">Random posts every item once per cycle in shuffled order and avoids repeating the same content back-to-back.</p>
                    </div>
                @endif
            @endif

            {{-- STEP 7: schedule --}}
            @if ($step === 7)
                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="space-y-4">
                        @if ($trigger_type === 'manual')
                            <div class="rounded-lg bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-800/50 dark:text-slate-300">Manual campaigns post only when you click <strong>Run Now</strong>. You can still set an end date below.</div>
                        @elseif ($trigger_type === 'interval')
                            <x-field label="How often?" for="interval_preset" required>
                                <select id="interval_preset" wire:model.live="interval_preset" class="input">
                                    @foreach (\App\Livewire\Campaigns\Wizard::INTERVAL_PRESETS as $seconds => $label)
                                        <option value="{{ $seconds }}">{{ $label }}</option>
                                    @endforeach
                                    <option value="custom">Custom interval…</option>
                                </select>
                            </x-field>
                            @if ($interval_preset === 'custom')
                                <x-field label="Every N minutes" for="custom_interval_minutes" required hint="e.g. 37 for every 37 minutes.">
                                    <input id="custom_interval_minutes" type="number" min="1" wire:model="custom_interval_minutes" class="input w-40">
                                </x-field>
                            @endif
                        @elseif ($trigger_type === 'fixed_time')
                            <div>
                                <span class="label">Posting times (every day) <span class="text-red-500">*</span></span>
                                <div class="space-y-2">
                                    @foreach ($fixed_times as $i => $slot)
                                        <div wire:key="ft-{{ $i }}" class="flex items-center gap-2">
                                            <input type="time" wire:model="fixed_times.{{ $i }}.time" class="input w-40">
                                            <button type="button" wire:click="removeFixedTime({{ $i }})" class="btn-icon hover:text-red-600" @disabled(count($fixed_times) === 1)><x-icon name="trash" class="size-4" /></button>
                                            @error("fixed_times.$i.time") <span class="error">{{ $message }}</span> @enderror
                                        </div>
                                    @endforeach
                                </div>
                                <button type="button" wire:click="addFixedTime" class="btn btn-ghost btn-sm mt-2"><x-icon name="plus" class="size-4" /> Add time</button>
                                @error('fixed_times') <p class="error">{{ $message }}</p> @enderror
                            </div>
                        @elseif ($trigger_type === 'daily')
                            <x-field label="Every day at" for="daily_time" required>
                                <input id="daily_time" type="time" wire:model="daily_time" class="input w-40">
                            </x-field>
                        @elseif ($trigger_type === 'weekly')
                            <div>
                                <span class="label">On these days <span class="text-red-500">*</span></span>
                                <div class="flex flex-wrap gap-2">
                                    @foreach (\App\Models\Schedule::WEEKDAYS as $num => $day)
                                        <label class="cursor-pointer">
                                            <input type="checkbox" wire:model="weekly_days" value="{{ $num }}" class="peer sr-only">
                                            <span class="inline-block rounded-lg px-3 py-1.5 text-sm ring-1 ring-slate-300 peer-checked:bg-brand-600 peer-checked:text-white peer-checked:ring-brand-600 dark:ring-slate-600">{{ $day }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('weekly_days') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <x-field label="At" for="weekly_time" required>
                                <input id="weekly_time" type="time" wire:model="weekly_time" class="input w-40">
                            </x-field>
                        @elseif ($trigger_type === 'scheduled')
                            <div>
                                <span class="label">Publish date & time for each item <span class="text-red-500">*</span></span>
                                <div class="space-y-2">
                                    @foreach ($items as $i => $item)
                                        <div wire:key="sch-{{ $item['key'] }}" class="flex items-center gap-2">
                                            <span class="w-6 text-right text-xs text-slate-400">{{ $i + 1 }}.</span>
                                            <span class="min-w-0 flex-1 truncate text-sm">{{ $item['title'] }}</span>
                                            <input type="datetime-local" wire:model="items.{{ $i }}.scheduled_at" class="input w-56">
                                        </div>
                                        @error("items.$i.scheduled_at") <p class="error ml-8">A date & time is required.</p> @enderror
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <x-field label="Timezone" for="timezone" required hint="Times above are in this timezone. Everything is stored in UTC.">
                            <select id="timezone" wire:model.live="timezone" class="input">
                                @foreach ($timezones as $tz)
                                    <option value="{{ $tz }}">{{ $tz }}</option>
                                @endforeach
                            </select>
                        </x-field>
                    </div>

                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-3">
                            <x-field label="Start date" for="start_date" :hint="$trigger_type === 'interval' ? 'Blank = start immediately on activation.' : 'Optional.'">
                                <input id="start_date" type="date" wire:model="start_date" class="input">
                            </x-field>
                            <x-field label="Start time" for="start_time">
                                <input id="start_time" type="time" wire:model="start_time" class="input">
                            </x-field>
                            <x-field label="End date" for="end_date" hint="Optional. No posts after this.">
                                <input id="end_date" type="date" wire:model="end_date" class="input">
                            </x-field>
                            <x-field label="End time" for="end_time">
                                <input id="end_time" type="time" wire:model="end_time" class="input">
                            </x-field>
                        </div>
                    </div>
                </div>
            @endif

            {{-- STEP 8: retry & failure --}}
            @if ($step === 8)
                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="space-y-4">
                        <div>
                            <span class="label">If a post fails</span>
                            <div class="space-y-2">
                                @foreach (\App\Enums\FailureBehavior::cases() as $case)
                                    <label wire:key="fb-{{ $case->value }}" class="flex cursor-pointer gap-3 rounded-lg p-3 ring-1 ring-slate-200 has-checked:bg-brand-50 has-checked:ring-brand-500 dark:ring-slate-700 dark:has-checked:bg-brand-500/10">
                                        <input type="radio" wire:model="failure_behavior" value="{{ $case->value }}" class="mt-0.5">
                                        <span><span class="block text-sm font-medium">{{ $case->label() }}</span><span class="block text-xs text-slate-500">{{ $case->description() }}</span></span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <x-field label="Maximum retries" for="max_retries" hint="For temporary errors.">
                                <input id="max_retries" type="number" min="0" max="20" wire:model="max_retries" class="input">
                            </x-field>
                            <x-field label="Retry delay (seconds)" for="retry_delay_seconds" hint="Doubles on each retry.">
                                <input id="retry_delay_seconds" type="number" min="5" wire:model="retry_delay_seconds" class="input">
                            </x-field>
                        </div>
                        <p class="text-xs text-slate-500">Telegram flood control (HTTP 429) is always respected: the bot waits exactly the <span class="kbd-pill">retry_after</span> time Telegram asks for.</p>
                    </div>
                    <div class="space-y-4">
                        <x-field label="After the last item" for="repeat_mode">
                            <select id="repeat_mode" wire:model.live="repeat_mode" class="input">
                                @foreach (\App\Enums\RepeatMode::options() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </x-field>
                        @if (in_array($repeat_mode, ['immediate', 'daily', 'weekly'], true))
                            <x-field label="Number of repeats" for="repeat_limit" hint="Blank = unlimited.">
                                <input id="repeat_limit" type="number" min="1" wire:model="repeat_limit" class="input w-40">
                            </x-field>
                        @endif
                    </div>
                </div>
            @endif

            {{-- STEP 9 / 10: review & activate --}}
            @if ($step >= 9)
                <div class="grid gap-6 lg:grid-cols-3">
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:col-span-2">
                        @foreach ([
                            'Name' => $name,
                            'Bot' => $selectedBot?->name ?? '—',
                            'Destination' => $selectedDestination ? $selectedDestination->name.' ('.$selectedDestination->chat_id.')' : '—',
                            'Test mode' => $test_mode ? 'On → '.($selectedTestDestination?->name ?? '?') : 'Off',
                            'Trigger' => \App\Enums\TriggerType::from($trigger_type)->label(),
                            'Posting order' => ucfirst($posting_order),
                            'Schedule' => match ($trigger_type) {
                                'interval' => $interval_preset === 'custom' ? "Every {$custom_interval_minutes} minutes" : (\App\Livewire\Campaigns\Wizard::INTERVAL_PRESETS[(int) $interval_preset] ?? ''),
                                'fixed_time' => collect($fixed_times)->pluck('time')->implode(', '),
                                'daily' => 'Every day at '.$daily_time,
                                'weekly' => collect($weekly_days)->map(fn ($d) => \App\Models\Schedule::WEEKDAYS[(int) $d] ?? $d)->implode(', ').' at '.$weekly_time,
                                'scheduled' => 'Per-item times',
                                default => 'Run Now only',
                            },
                            'Timezone' => $timezone,
                            'Start' => $start_date ? trim($start_date.' '.$start_time) : 'On activation',
                            'End' => $end_date ? trim($end_date.' '.$end_time) : 'No end date',
                            'On failure' => \App\Enums\FailureBehavior::from($failure_behavior)->label().' · '.$max_retries.' retries · '.$retry_delay_seconds.'s delay',
                            'Repeat' => \App\Enums\RepeatMode::from($repeat_mode)->label().($repeat_limit !== '' && in_array($repeat_mode, ['immediate', 'daily', 'weekly']) ? " ({$repeat_limit}×)" : ''),
                        ] as $label => $value)
                            <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                                <dt class="text-xs text-slate-500">{{ $label }}</dt>
                                <dd class="font-medium text-slate-900 dark:text-white">{{ $value }}</dd>
                            </div>
                        @endforeach
                        <div class="rounded-lg bg-slate-50 px-3 py-2 sm:col-span-2 dark:bg-slate-800/50">
                            <dt class="text-xs text-slate-500">Sequence ({{ count($items) }} items)</dt>
                            <dd class="mt-1 flex flex-wrap gap-1">
                                @foreach ($items as $i => $item)
                                    <x-badge color="slate">{{ $i + 1 }}. {{ str($item['title'])->limit(30) }}</x-badge>
                                @endforeach
                            </dd>
                        </div>
                    </dl>
                    <div>
                        <div class="rounded-xl bg-brand-50 p-4 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:ring-brand-500/30">
                            <div class="flex items-center gap-2 text-sm font-semibold text-brand-900 dark:text-brand-200"><x-icon name="calendar" class="size-4" /> Upcoming posts</div>
                            @if ($previewError)
                                <p class="mt-2 text-xs text-red-600">{{ $previewError }}</p>
                            @elseif ($trigger_type === 'manual')
                                <p class="mt-2 text-xs text-brand-800 dark:text-brand-200">Only when you click Run Now.</p>
                            @elseif (empty($previewRuns))
                                <p class="mt-2 text-xs text-brand-800 dark:text-brand-200">No upcoming run times — check the end date.</p>
                            @else
                                <ol class="mt-2 space-y-1 text-sm">
                                    @foreach ($previewRuns as $i => $run)
                                        <li class="flex justify-between gap-2">
                                            <span class="truncate text-brand-900 dark:text-brand-100">{{ $posting_order === 'random' && $trigger_type !== 'scheduled' ? 'Random item' : ($items[$i % max(1, count($items))]['title'] ?? '').($i >= count($items) ? ' (repeat)' : '') }}</span>
                                            <span class="shrink-0 tabular-nums text-brand-700 dark:text-brand-300">{{ $run->setTimezone($timezone)->format('M j, g:i A') }}</span>
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                        </div>
                    </div>
                </div>

                @if ($step === 10)
                    <div class="mt-6 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/30">
                        <strong>Ready.</strong> Save as a draft to activate later, or activate now: posting is scheduled immediately and handled by the queue workers.
                        @if ($test_mode) Test mode is <strong>on</strong> — posts go to the test chat. @endif
                    </div>
                @endif
            @endif
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-5 py-4 dark:border-slate-800">
            <div>
                @if ($step > 1)
                    <button type="button" wire:click="back" class="btn btn-secondary"><x-icon name="chevron-left" class="size-4" /> Back</button>
                @else
                    <a href="{{ $existing ? route('campaigns.show', $existing) : route('campaigns.index') }}" wire:navigate class="btn btn-ghost">Cancel</a>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($step < 10)
                    @if ($existing)
                        <button type="button" wire:click="save" class="btn btn-secondary" wire:loading.attr="disabled">Save changes</button>
                    @endif
                    <button type="button" wire:click="next" class="btn btn-primary">Next <x-icon name="chevron-right" class="size-4" /></button>
                @else
                    @php $canActivate = ! $existing || in_array($existing->status, [\App\Enums\CampaignStatus::Draft, \App\Enums\CampaignStatus::Completed, \App\Enums\CampaignStatus::Cancelled, \App\Enums\CampaignStatus::Failed], true); @endphp
                    <button type="button" wire:click="save" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="save">
                        {{ $existing && $existing->status !== \App\Enums\CampaignStatus::Draft ? 'Save changes' : 'Save as draft' }}
                    </button>
                    @if ($canActivate)
                        @can('campaigns.operate')
                            <button type="button" wire:click="save(true)" class="btn btn-success" wire:loading.attr="disabled" wire:target="save">
                                <span wire:loading wire:target="save"><x-spinner /></span>
                                <x-icon name="bolt" class="size-4" /> {{ $existing && $existing->status !== \App\Enums\CampaignStatus::Draft ? 'Save & re-activate' : 'Save & activate' }}
                            </button>
                        @endcan
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
