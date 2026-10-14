<div>
    <x-page-header title="Quick Send" description="Save a bot + channel / group once. Then just type a message and press Send — it is posted right away.">
        <x-slot:actions>
            @if ($targets->isNotEmpty())
                <button type="button" wire:click="create" class="btn btn-secondary"><x-icon name="plus" class="size-4" /> New target</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @php $following = ($lastLog && $lastLog->status === \App\Enums\PostingStatus::Processing) || $recent->contains(fn ($l) => $l->status === \App\Enums\PostingStatus::Processing); @endphp
    @if ($following)
        <div wire:poll.1s class="hidden"></div>
    @endif

    @if ($targets->isEmpty())
        <div class="card">
            <x-empty icon="send" title="No Quick Send targets yet" description="A target is a saved bot + channel or group. Create one, then type a message and press Send.">
                @if ($bots->isEmpty())
                    <a href="{{ route('bots.index') }}" wire:navigate class="btn btn-primary"><x-icon name="bot" class="size-4" /> Add a bot first</a>
                @else
                    <button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Create your first target</button>
                @endif
            </x-empty>
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Saved targets --}}
            <aside class="card h-fit p-2 lg:col-span-1">
                <div class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Send to</div>
                <div class="space-y-1">
                    @foreach ($targets as $t)
                        <div wire:key="t-{{ $t->id }}" @class([
                            'group flex items-center gap-2 rounded-lg px-3 py-2.5',
                            'bg-brand-50 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:ring-brand-500/30' => $target?->id === $t->id,
                            'hover:bg-slate-50 dark:hover:bg-slate-800/60' => $target?->id !== $t->id,
                        ])>
                            <button type="button" wire:click="select({{ $t->id }})" class="flex min-w-0 flex-1 items-center gap-3 text-left">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-brand-700 dark:bg-brand-500/20 dark:text-brand-200">
                                    <x-icon :name="$t->destination?->type === \App\Enums\DestinationType::Channel ? 'megaphone' : 'user-group'" class="size-4" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-slate-900 dark:text-white">{{ $t->name }}</span>
                                    <span class="block truncate text-xs text-slate-500">{{ $t->bot?->displayUsername() ?? 'deleted bot' }} → {{ $t->destination?->name ?? 'deleted chat' }}</span>
                                </span>
                            </button>
                            <div class="flex shrink-0 items-center opacity-60 group-hover:opacity-100">
                                <button type="button" wire:click="edit({{ $t->id }})" class="btn-icon" title="Edit"><x-icon name="pencil" class="size-4" /></button>
                                <x-confirm action="deleteTarget({{ $t->id }})" class="btn-icon hover:text-red-600" title="Delete target?" :message="'Delete “'.$t->name.'”? Messages already sent are not affected.'" confirm-text="Delete">
                                    <x-icon name="trash" class="size-4" />
                                </x-confirm>
                            </div>
                        </div>
                    @endforeach
                </div>
            </aside>

            <section class="space-y-6 lg:col-span-2">
                @if ($target)
                    {{-- Compose --}}
                    <form wire:submit="send" class="card p-5" x-data="{ count: 0 }" x-on:quick-send-sent.window="count = 0; $nextTick(() => $refs.box.focus())">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">To</div>
                                <div class="flex items-center gap-2">
                                    <span class="truncate text-base font-semibold text-slate-900 dark:text-white">{{ $target->destination?->name }}</span>
                                    @if ($target->destination)
                                        <x-badge :color="$target->destination->type->color()">{{ $target->destination->type->label() }}</x-badge>
                                    @endif
                                </div>
                                <div class="text-xs text-slate-500">via {{ $target->bot?->name }} ({{ $target->bot?->displayUsername() }})</div>
                            </div>
                            <div class="flex flex-wrap gap-1">
                                @if ($target->parse_mode) <x-badge color="indigo">{{ $target->parse_mode->value }}</x-badge> @endif
                                @if ($target->disable_notification) <x-badge>Silent</x-badge> @endif
                                @if ($target->disable_web_page_preview) <x-badge>No link preview</x-badge> @endif
                                @if ($target->protect_content) <x-badge>Protected</x-badge> @endif
                            </div>
                        </div>

                        @if ($target->bot && ! $target->bot->isActive())
                            <x-docs.callout type="warning" class="mt-3">Bot “{{ $target->bot->name }}” is disabled. Enable it on the <a href="{{ route('bots.index') }}" wire:navigate class="underline">Bots</a> page.</x-docs.callout>
                        @elseif ($target->destination && $target->destination->connection_status === \App\Enums\ConnectionStatus::Failed)
                            <x-docs.callout type="warning" class="mt-3">The last connection test of this chat failed. Check that the bot is still an admin with “Post messages”.</x-docs.callout>
                        @endif

                        <div class="mt-4">
                            <label for="quick-message" class="sr-only">Message</label>
                            <textarea id="quick-message" x-ref="box" wire:model="message" rows="6" maxlength="{{ \App\Services\QuickSendService::MAX_LENGTH }}" autofocus
                                      x-on:input="count = $event.target.value.length"
                                      x-on:keydown.ctrl.enter.prevent="$wire.send()" x-on:keydown.meta.enter.prevent="$wire.send()"
                                      class="input font-normal leading-relaxed" placeholder="Type your message…"></textarea>
                            @error('message') <p class="error">{{ $message }}</p> @enderror
                        </div>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                            <div class="text-xs text-slate-500">
                                <span class="tabular-nums" x-text="count.toLocaleString()">0</span> / {{ number_format(\App\Services\QuickSendService::MAX_LENGTH) }}
                                <span class="mx-1">·</span> <span class="kbd-pill">Ctrl</span> + <span class="kbd-pill">Enter</span> to send
                            </div>
                            <button type="submit" class="btn btn-primary px-6" wire:loading.attr="disabled" wire:target="send">
                                <span wire:loading.remove wire:target="send"><x-icon name="send" class="size-4" /></span>
                                <span wire:loading wire:target="send"><x-spinner /></span>
                                Send
                            </button>
                        </div>

                        @if ($lastLog)
                            <div @class([
                                'mt-4 flex items-start gap-2 rounded-lg p-3 text-sm',
                                'bg-brand-50 text-brand-900 dark:bg-brand-500/10 dark:text-brand-100' => $lastLog->status === \App\Enums\PostingStatus::Processing,
                                'bg-emerald-50 text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-200' => $lastLog->status === \App\Enums\PostingStatus::Sent,
                                'bg-red-50 text-red-900 dark:bg-red-500/10 dark:text-red-200' => $lastLog->status === \App\Enums\PostingStatus::Failed,
                            ])>
                                @if ($lastLog->status === \App\Enums\PostingStatus::Processing)
                                    <x-spinner class="mt-0.5 size-4" /> <span>Sending… {{ $lastLog->error_message }}</span>
                                @elseif ($lastLog->status === \App\Enums\PostingStatus::Sent)
                                    <x-icon name="check-circle" class="mt-0.5 size-5 shrink-0" />
                                    <span>Sent at {{ display_time($lastLog->sent_at, 'H:i:s') }}{{ $lastLog->telegram_message_id ? ' · message #'.$lastLog->telegram_message_id : '' }}{{ $lastLog->duration_ms ? ' · '.$lastLog->duration_ms.' ms' : '' }}</span>
                                @else
                                    <x-icon name="x-circle" class="mt-0.5 size-5 shrink-0" /> <span>Not sent: {{ $lastLog->error_message }}</span>
                                @endif
                            </div>
                        @endif
                    </form>

                    {{-- History --}}
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Recent messages to {{ $target->destination?->name }}</h2>
                            <a href="{{ route('logs.index') }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">All posting logs →</a>
                        </div>
                        @if ($recent->isEmpty())
                            <p class="px-5 py-8 text-center text-sm text-slate-500">Nothing sent to this target yet.</p>
                        @else
                            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach ($recent as $log)
                                    <li wire:key="log-{{ $log->id }}" class="flex gap-3 px-5 py-3">
                                        <div class="w-20 shrink-0 pt-0.5"><x-badge :color="$log->status->color()">{{ $log->status->label() }}</x-badge></div>
                                        <div class="min-w-0 flex-1">
                                            <p class="line-clamp-3 whitespace-pre-line break-words text-sm text-slate-700 dark:text-slate-200">{{ $log->message }}</p>
                                            @if ($log->status === \App\Enums\PostingStatus::Failed && $log->error_message)
                                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $log->error_message }}</p>
                                            @endif
                                        </div>
                                        <div class="shrink-0 text-right text-xs text-slate-500" title="{{ display_time($log->created_at) }}">{{ relative_time($log->created_at) }}</div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @else
                    <div class="card"><x-empty icon="send" title="Choose a target" description="Pick where to send from the list." /></div>
                @endif
            </section>
        </div>
    @endif

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit Quick Send target' : 'New Quick Send target'" max-width="lg">
        <form wire:submit="saveTarget" id="qs-form" class="space-y-4">
            <x-field label="Bot" for="form.bot_id" required>
                <select id="form.bot_id" wire:model.live="form.bot_id" class="input">
                    <option value="">Choose a bot…</option>
                    @foreach ($bots as $bot)
                        <option value="{{ $bot->id }}">{{ $bot->name }} ({{ $bot->displayUsername() }}){{ $bot->isActive() ? '' : ' — disabled' }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field label="Channel / group" for="form.destination_id" required :hint="$form['bot_id'] !== '' && $formDestinations->isEmpty() ? 'This bot has no channels or groups yet. Add one on the Channels or Groups page.' : null">
                <select id="form.destination_id" wire:model.live="form.destination_id" class="input" @disabled($form['bot_id'] === '')>
                    <option value="">Choose a channel or group…</option>
                    @foreach ($formDestinations as $d)
                        <option value="{{ $d->id }}">{{ $d->name }} ({{ $d->type->label() }})</option>
                    @endforeach
                </select>
            </x-field>
            <x-field label="Name" for="form.name" required hint="Shown in the Send to list, e.g. “Main channel”.">
                <input id="form.name" type="text" wire:model="form.name" class="input" maxlength="120">
            </x-field>
            <x-field label="Formatting" for="form.parse_mode" hint="HTML lets you use b, i, u and a tags for bold, italic, underline and links.">
                <select id="form.parse_mode" wire:model="form.parse_mode" class="input">
                    <option value="">Plain text</option>
                    @foreach (\App\Enums\ParseMode::options() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </x-field>
            <div class="space-y-2 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.disable_notification" class="checkbox"> Send silently (no notification sound)</label>
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.disable_web_page_preview" class="checkbox"> No link preview</label>
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="form.protect_content" class="checkbox"> Protect from forwarding and saving</label>
            </div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-secondary" x-on:click="show = false">Cancel</button>
            <button type="submit" form="qs-form" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveTarget">Save target</button>
        </x-slot:footer>
    </x-modal>
</div>
