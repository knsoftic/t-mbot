<div wire:poll.30s>
    <x-page-header title="Auto Queue" description="Set a channel and posting times once. At every time, the next item of your Content Library is posted — new content joins the line by itself, and after the last item it starts again from the first.">
        <x-slot:actions>
            @can('campaigns.manage')
                <button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" class="size-4" /> New auto queue</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($queues->isEmpty())
        <div class="card">
            <x-empty icon="calendar" title="No auto queue yet" description="Choose a channel and the times of day to post — for example 09:00, 14:00 and 20:00. That is all; the Content Library posts itself from then on.">
                @can('campaigns.manage')
                    <button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Create your auto queue</button>
                @endcan
            </x-empty>
        </div>
    @endif

    <div class="space-y-5">
        @foreach ($queues as $queue)
            @php
                $d = $details[$queue->id];
                $weekdays = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
                $postedThisRound = max(0, $d['total'] - $d['remaining']);
            @endphp
            <div class="card" wire:key="q-{{ $queue->id }}">
                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 p-5 dark:border-slate-800">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ $queue->name }}</h2>
                            <x-badge :color="$queue->isActive() ? 'green' : 'slate'" :dot="$queue->isActive()">{{ $queue->isActive() ? 'Active' : 'Paused' }}</x-badge>
                        </div>
                        <div class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                            To <strong>{{ $queue->destination?->name ?? 'deleted chat' }}</strong> via {{ $queue->bot?->name ?? 'deleted bot' }}
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            @if ($queue->isInterval())
                                <span class="rounded-md bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">{{ $queue->intervalLabel() }}</span>
                                @if ($queue->active_from)
                                    <span class="text-xs text-slate-500">between {{ $queue->active_from }}–{{ $queue->active_until }}</span>
                                @endif
                            @endif
                            @foreach ($queue->times ?? [] as $time)
                                <span class="rounded-md bg-brand-50 px-2 py-0.5 font-mono text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">{{ $time }}</span>
                            @endforeach
                            <span class="text-xs text-slate-500">· {{ $queue->days ? collect($queue->days)->map(fn ($day) => $weekdays[$day])->implode(', ') : 'Every day' }} · {{ $queue->timezone }}</span>
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            {{ \App\Models\AutoQueue::ORDERS[$queue->order] }}{{ $queue->tag ? ' · only tag “'.$queue->tag->name.'”' : ' · whole library' }}
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @can('campaigns.operate')
                            <x-confirm action="postNow({{ $queue->id }})" title="Post the next item now?" message="The next item of the library is posted right away. The regular posting times stay the same." confirm-text="Post now" :danger="false" class="btn-secondary btn-sm">
                                <x-icon name="send" class="size-4" /> Post next now
                            </x-confirm>
                            <button type="button" wire:click="toggle({{ $queue->id }})" class="btn btn-secondary btn-sm">
                                <x-icon :name="$queue->isActive() ? 'pause' : 'play'" class="size-4" /> {{ $queue->isActive() ? 'Pause' : 'Resume' }}
                            </button>
                        @endcan
                        @can('campaigns.manage')
                            <button type="button" wire:click="edit({{ $queue->id }})" class="btn-icon" title="Edit"><x-icon name="pencil" class="size-4" /></button>
                            <x-confirm action="delete({{ $queue->id }})" class="btn-icon hover:text-red-600" title="Delete auto queue?" :message="'Delete “'.$queue->name.'”? Your content stays in the library.'" confirm-text="Delete">
                                <x-icon name="trash" class="size-4" />
                            </x-confirm>
                        @endcan
                    </div>
                </div>

                @if ($queue->last_error)
                    <div class="border-b border-amber-100 bg-amber-50 px-5 py-2.5 text-sm text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
                        <x-icon name="warning" class="mr-1 inline size-4" /> {{ $queue->last_error }}
                    </div>
                @endif

                <div class="grid gap-6 p-5 lg:grid-cols-3">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Progress</div>
                        <div class="mt-2 text-sm">Round <strong>{{ $queue->round }}</strong> · {{ number_format($postedThisRound) }} of {{ number_format($d['total']) }} items posted</div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div class="h-full rounded-full bg-brand-500" style="width: {{ $d['total'] ? round($postedThisRound / $d['total'] * 100) : 0 }}%"></div>
                        </div>
                        @if ($d['total'] === 0)
                            <p class="mt-2 text-xs text-red-600">No active content{{ $queue->tag ? ' with this tag' : '' }}. <a href="{{ route('contents.create') }}" wire:navigate class="underline">Add content</a>.</p>
                        @else
                            <p class="mt-2 text-xs text-slate-500">After the last item it starts again from the first.</p>
                        @endif
                    </div>

                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Coming up</div>
                        @if (! $queue->isActive())
                            <p class="mt-2 text-sm text-slate-500">Paused.</p>
                        @else
                            <ul class="mt-2 space-y-1.5 text-sm">
                                @foreach ($d['upcoming'] as [$slot, $content])
                                    <li class="flex gap-2">
                                        <span class="w-24 shrink-0 tabular-nums text-xs text-slate-500">{{ display_time($slot, $queue->isInterval() && $queue->interval_seconds < 60 ? 'D H:i:s' : 'D H:i') }}</span>
                                        <span class="truncate">{{ $content?->title ?? ($queue->order === 'random' ? 'Random item' : '—') }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Last posts</div>
                        @if ($d['recent']->isEmpty())
                            <p class="mt-2 text-sm text-slate-500">Nothing posted yet.</p>
                        @else
                            <ul class="mt-2 space-y-1.5 text-sm">
                                @foreach ($d['recent'] as $post)
                                    <li class="flex items-start gap-2">
                                        <x-badge :color="match ($post->status) { 'sent' => 'green', 'failed' => 'red', default => 'blue' }" class="shrink-0">{{ ucfirst($post->status) }}</x-badge>
                                        <span class="min-w-0">
                                            <span class="block truncate">{{ $post->content?->title ?? 'Deleted content' }}</span>
                                            <span class="block text-xs text-slate-500">{{ relative_time($post->created_at) }}@if ($post->status === 'failed' && $post->log?->error_message) · <span class="text-red-600">{{ str($post->log->error_message)->limit(60) }}</span>@endif</span>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit auto queue' : 'New auto queue'" max-width="2xl">
        <form wire:submit="save" id="aq-form" class="space-y-5">
            <x-field label="Name" for="form.name" required>
                <input id="form.name" type="text" wire:model="form.name" class="input" maxlength="120">
            </x-field>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Bot" for="form.bot_id" required>
                    <select id="form.bot_id" wire:model.live="form.bot_id" class="input">
                        <option value="">Choose a bot…</option>
                        @foreach ($bots as $bot)
                            <option value="{{ $bot->id }}">{{ $bot->name }} ({{ $bot->displayUsername() }})</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field label="Channel / group" for="form.destination_id" required>
                    <select id="form.destination_id" wire:model="form.destination_id" class="input" @disabled(($form['bot_id'] ?? '') === '')>
                        <option value="">Choose…</option>
                        @foreach ($destinations as $destination)
                            <option value="{{ $destination->id }}">{{ $destination->name }} ({{ $destination->type->label() }})</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            @php
                $interval = ($form['mode'] ?? 'times') === 'interval';
                $currentSeconds = (int) ($form['interval_value'] ?? 0) * (['seconds' => 1, 'minutes' => 60, 'hours' => 3600][$form['interval_unit'] ?? 'minutes'] ?? 60);
            @endphp
            <div class="space-y-3">
                <div class="label">How often <span class="text-red-500">*</span></div>

                {{-- Quick buttons --}}
                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Livewire\AutoQueues\Index::PRESETS as $key => [$label, $presetMode, $seconds])
                        @php $chosen = $presetMode === 'interval' ? ($interval && $currentSeconds === $seconds) : (! $interval && ($form['times'] ?? []) === ['09:00']); @endphp
                        <button type="button" wire:click="preset('{{ $key }}')" @class([
                            'rounded-full px-3 py-1.5 text-xs font-medium ring-1 transition',
                            'bg-brand-600 text-white ring-brand-600' => $chosen,
                            'text-slate-700 ring-slate-300 hover:bg-slate-50 dark:text-slate-200 dark:ring-slate-600 dark:hover:bg-slate-800' => ! $chosen,
                        ])>{{ $label }}</button>
                    @endforeach
                </div>

                {{-- Custom --}}
                <div class="inline-flex rounded-lg p-0.5 ring-1 ring-slate-200 dark:ring-slate-700">
                    <button type="button" wire:click="$set('form.mode', 'interval')" @class(['rounded-md px-3 py-1.5 text-sm font-medium', 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200' => $interval, 'text-slate-600 dark:text-slate-300' => ! $interval])>Every … (custom interval)</button>
                    <button type="button" wire:click="$set('form.mode', 'times')" @class(['rounded-md px-3 py-1.5 text-sm font-medium', 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200' => ! $interval, 'text-slate-600 dark:text-slate-300' => $interval])>At set times of day</button>
                </div>

                @if ($interval)
                    <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/50">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span>Post every</span>
                            <input type="number" min="1" wire:model.live.debounce.400ms="form.interval_value" class="input w-24" aria-label="Interval">
                            <select wire:model.live="form.interval_unit" class="input w-32" aria-label="Unit">
                                <option value="seconds">seconds</option>
                                <option value="minutes">minutes</option>
                                <option value="hours">hours</option>
                            </select>
                        </div>
                        @error('form.interval_value') <p class="error">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-slate-500">Fastest: every {{ \App\Models\AutoQueue::MIN_INTERVAL_SECONDS }} seconds — Telegram allows only about 20 posts per minute in one chat.</p>

                        <div class="mt-4 flex flex-wrap items-center gap-2 text-sm">
                            <span>Only between</span>
                            <input type="time" wire:model="form.active_from" class="input w-32" aria-label="From">
                            <span>and</span>
                            <input type="time" wire:model="form.active_until" class="input w-32" aria-label="Until">
                            <span class="text-xs text-slate-500">(optional — e.g. 09:00 and 22:00 to stay quiet at night)</span>
                        </div>
                        @error('form.active_from') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/50">
                        <div class="flex flex-wrap items-center gap-2">
                            @foreach ($form['times'] ?? [] as $i => $time)
                                <div class="flex items-center gap-1" wire:key="t-{{ $i }}">
                                    <input type="time" wire:model="form.times.{{ $i }}" class="input w-32">
                                    @if (count($form['times']) > 1)
                                        <button type="button" wire:click="removeTime({{ $i }})" class="btn-icon" title="Remove"><x-icon name="x" class="size-4" /></button>
                                    @endif
                                </div>
                            @endforeach
                            <button type="button" wire:click="addTime" class="btn btn-secondary btn-sm"><x-icon name="plus" class="size-4" /> Add time</button>
                        </div>
                        @error('form.times') <p class="error">{{ $message }}</p> @enderror
                        @foreach ($errors->get('form.times.*') as $messages) <p class="error">{{ $messages[0] }}</p> @endforeach
                    </div>
                @endif
            </div>

            <div>
                <div class="label">Days</div>
                <div class="flex flex-wrap gap-2">
                    @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $value => $label)
                        <label class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm ring-1 ring-slate-200 dark:ring-slate-700">
                            <input type="checkbox" wire:model="form.days" value="{{ $value }}" class="checkbox"> {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('form.days') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field label="Timezone" for="form.timezone" required>
                    <select id="form.timezone" wire:model="form.timezone" class="input">
                        @foreach ($timezones as $tz) <option value="{{ $tz }}">{{ $tz }}</option> @endforeach
                    </select>
                </x-field>
                <x-field label="Which content" for="form.tag_id" hint="Leave on “Whole library” to post everything active.">
                    <select id="form.tag_id" wire:model="form.tag_id" class="input">
                        <option value="">Whole library</option>
                        @foreach ($tags as $tag) <option value="{{ $tag->id }}">Tag: {{ $tag->name }}</option> @endforeach
                    </select>
                </x-field>
                <x-field label="Order" for="form.order">
                    <select id="form.order" wire:model="form.order" class="input">
                        @foreach (\App\Models\AutoQueue::ORDERS as $value => $label) <option value="{{ $value }}">{{ $label }}</option> @endforeach
                    </select>
                </x-field>
            </div>

            <x-docs.callout type="info">Only <strong>active</strong> content is posted (deactivate an item in the Content Library to leave it out). Posts count towards your plan's posting limit and appear in Posting Logs.</x-docs.callout>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-secondary" x-on:click="show = false">Cancel</button>
            <button type="submit" form="aq-form" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">Save</button>
        </x-slot:footer>
    </x-modal>
</div>
