<div @if ($polling) wire:poll.2s @endif>
    <x-page-header :title="$pageTitle" description="Telegram channels, groups and supergroups that campaigns post to. Use a numeric chat ID or a public @username.">
        <x-slot:actions>
            @can('destinations.manage')
                <button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Add {{ $scope === 'group' ? 'group' : ($scope === 'channel' ? 'channel' : 'destination') }}</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($bots->isEmpty())
        <div class="mb-4 flex items-start gap-3 rounded-xl bg-amber-50 p-4 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30">
            <x-icon name="warning" class="size-5" />
            <div>Add a bot first — every destination is reached through one of your bots. <a href="{{ route('bots.index') }}" wire:navigate class="font-semibold underline">Go to Bots</a></div>
        </div>
    @endif

    <div class="card">
        <div class="flex flex-col gap-3 border-b border-slate-200 p-4 md:flex-row md:items-center dark:border-slate-800">
            <div class="relative flex-1">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search name, chat ID, @username…" class="input pl-9">
            </div>
            <select wire:model.live="botFilter" class="input md:w-48">
                <option value="">All bots</option>
                @foreach ($bots as $bot)
                    <option value="{{ $bot->id }}">{{ $bot->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="status" class="input md:w-40">
                <option value="">All statuses</option>
                @foreach (\App\Enums\DestinationStatus::options() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        @if ($destinations->isEmpty())
            <x-empty :icon="$scope === 'group' ? 'user-group' : 'megaphone'" :title="'No '.strtolower($pageTitle).' yet'"
                     description="Add the bot to your channel or group first, then register the chat here and send a test message." />
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Destination</th>
                            <th>Type</th>
                            <th class="hidden md:table-cell">Bot</th>
                            <th>Connection</th>
                            <th class="hidden lg:table-cell">Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($destinations as $destination)
                            <tr wire:key="dest-{{ $destination->id }}">
                                <td>
                                    <div class="font-medium text-slate-900 dark:text-white">
                                        {{ $destination->name }}
                                        @if ($destination->is_test_chat)
                                            <x-badge color="purple" class="ml-1">Test chat</x-badge>
                                        @endif
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        <span class="font-mono">{{ $destination->chat_id }}</span>
                                        @if ($destination->username && ! str_starts_with($destination->chat_id, '@')) · {{ '@'.$destination->username }} @endif
                                        @if ($destination->telegram_title && $destination->telegram_title !== $destination->name) · “{{ $destination->telegram_title }}” @endif
                                    </div>
                                </td>
                                <td><x-status :value="$destination->type" :dot="false" /></td>
                                <td class="hidden text-sm md:table-cell">{{ $destination->bot?->name ?? '—' }}</td>
                                <td>
                                    @if ($destination->connection_status === \App\Enums\ConnectionStatus::Testing)
                                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-600"><x-spinner /> Testing…</span>
                                    @else
                                        <x-status :value="$destination->connection_status" :dot="false" />
                                    @endif
                                    @if ($destination->connection_status === \App\Enums\ConnectionStatus::Failed && $destination->last_error)
                                        <div class="mt-1 max-w-xs text-xs text-red-600 dark:text-red-400">{{ str($destination->last_error)->limit(160) }}</div>
                                    @elseif ($destination->connection_status === \App\Enums\ConnectionStatus::Connected)
                                        <div class="mt-1 text-xs text-slate-400">
                                            {{ $destination->bot_is_admin ? 'Bot is admin' : 'Bot is member' }} · can post · {{ relative_time($destination->last_checked_at) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="hidden lg:table-cell"><x-status :value="$destination->status" /></td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        @can('destinations.manage')
                                            <button type="button" wire:click="test({{ $destination->id }})" class="btn btn-secondary btn-sm" title="Verify access and send a test message"
                                                    @disabled($destination->connection_status === \App\Enums\ConnectionStatus::Testing)>
                                                <x-icon name="send" class="size-4" /> Test message
                                            </button>
                                            <button type="button" wire:click="test({{ $destination->id }}, false)" class="btn-icon" title="Check permissions without posting">
                                                <x-icon name="shield" class="size-4" />
                                            </button>
                                            <button type="button" wire:click="edit({{ $destination->id }})" class="btn-icon" title="Edit"><x-icon name="pencil" class="size-4" /></button>
                                            <x-confirm action="delete({{ $destination->id }})" class="btn-icon hover:text-red-600" title="Delete destination?"
                                                       :message="'Delete “'.$destination->name.'”? Posting history is kept.'" confirm-text="Delete">
                                                <x-icon name="trash" class="size-4" />
                                            </x-confirm>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($destinations->hasPages())
                <div class="border-t border-slate-200 px-4 py-3 dark:border-slate-800">{{ $destinations->links() }}</div>
            @endif
        @endif
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit destination' : 'Add destination'" max-width="xl">
        <form wire:submit="save" id="destination-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-field label="Bot" for="form.bot_id" required class="sm:col-span-2">
                <select id="form.bot_id" wire:model="form.bot_id" class="input">
                    <option value="">Select a bot…</option>
                    @foreach ($bots as $bot)
                        <option value="{{ $bot->id }}">{{ $bot->name }}{{ $bot->username ? ' (@'.$bot->username.')' : '' }}{{ $bot->status->value === 'disabled' ? ' — disabled' : '' }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field label="Name" for="form.name" required hint="How this chat is shown in the panel.">
                <input id="form.name" type="text" wire:model="form.name" class="input" maxlength="120">
            </x-field>

            <x-field label="Type" for="form.type" required>
                <select id="form.type" wire:model.live="form.type" class="input">
                    @foreach (\App\Enums\DestinationType::options() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field label="Chat ID or @username" for="form.chat_id" required class="sm:col-span-2"
                     hint="Public chats: @channelname or t.me/channelname. Private chats: the numeric ID, e.g. -1001234567890. After a successful test the numeric ID is stored automatically.">
                <input id="form.chat_id" type="text" wire:model="form.chat_id" class="input font-mono" maxlength="100" placeholder="@my_channel or -1001234567890">
            </x-field>

            @if (($form['type'] ?? '') === 'channel')
                <div class="flex gap-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-900 sm:col-span-2 dark:bg-amber-500/10 dark:text-amber-200">
                    <x-icon name="info" class="size-5 shrink-0" />
                    <div><strong>Channels:</strong> the bot must be an <strong>administrator</strong> of the channel with the <strong>“Post messages”</strong> permission. Open the channel → Administrators → Add admin → search your bot.</div>
                </div>
            @else
                <div class="flex gap-3 rounded-lg bg-brand-50 p-3 text-xs text-brand-900 sm:col-span-2 dark:bg-brand-500/10 dark:text-brand-200">
                    <x-icon name="info" class="size-5 shrink-0" />
                    <div><strong>Groups:</strong> add the bot as a member (or admin) and make sure members are allowed to send messages.</div>
                </div>
            @endif

            <x-field label="Status" for="form.status">
                <select id="form.status" wire:model="form.status" class="input">
                    @foreach (\App\Enums\DestinationStatus::options() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </x-field>

            <div class="flex items-end pb-2">
                <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                    <input type="checkbox" wire:model="form.is_test_chat" class="checkbox">
                    Use as a test chat (for Test Mode)
                </label>
            </div>

            <x-field label="Description" for="form.description" class="sm:col-span-2">
                <textarea id="form.description" wire:model="form.description" rows="2" class="input" maxlength="2000"></textarea>
            </x-field>
        </form>

        <x-slot:footer>
            <button type="button" class="btn btn-secondary" x-on:click="show = false">Cancel</button>
            <button type="submit" form="destination-form" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading wire:target="save"><x-spinner /></span> Save
            </button>
        </x-slot:footer>
    </x-modal>
</div>
