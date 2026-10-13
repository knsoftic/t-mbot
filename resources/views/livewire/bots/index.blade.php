<div>
    <x-page-header title="Bots" description="Telegram bots used to publish posts. Each bot works independently and can serve its own channels and groups.">
        <x-slot:actions>
            @can('bots.manage')
                <button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Add bot</button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center dark:border-slate-800">
            <div class="relative flex-1">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search by name or @username…" class="input pl-9">
            </div>
            <select wire:model.live="status" class="input sm:w-44">
                <option value="">All statuses</option>
                @foreach (\App\Enums\BotStatus::options() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="relative">
            <div wire:loading.flex wire:target="search,status,gotoPage,nextPage,previousPage" class="absolute inset-0 z-10 items-center justify-center bg-white/60 dark:bg-slate-900/60">
                <x-spinner class="size-6 text-brand-600" />
            </div>

            @if ($bots->isEmpty())
                <x-empty icon="bot" :title="$search || $status ? 'No bots match your filters' : 'No bots yet'"
                         description="Create a bot with @BotFather in Telegram, copy its token and add it here.">
                    @can('bots.manage')
                        @unless ($search || $status)
                            <button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Add your first bot</button>
                        @endunless
                    @endcan
                </x-empty>
            @else
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Bot</th>
                                <th>Status</th>
                                <th>Connection</th>
                                <th class="hidden md:table-cell">Usage</th>
                                <th class="hidden lg:table-cell">Last activity</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bots as $bot)
                                <tr wire:key="bot-{{ $bot->id }}">
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300">
                                                <x-icon name="bot" class="size-5" />
                                            </div>
                                            <div class="min-w-0">
                                                <div class="font-medium text-slate-900 dark:text-white">{{ $bot->name }}</div>
                                                <div class="text-xs text-slate-500">{{ $bot->displayUsername() }} · <span class="font-mono">{{ $bot->maskedToken() }}</span></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><x-status :value="$bot->status" /></td>
                                    <td>
                                        <x-status :value="$bot->connection_status" :dot="false" />
                                        @if ($bot->last_error && $bot->connection_status === \App\Enums\ConnectionStatus::Failed)
                                            <div class="mt-1 max-w-xs text-xs text-red-600 dark:text-red-400">{{ str($bot->last_error)->limit(110) }}</div>
                                        @elseif ($bot->last_checked_at)
                                            <div class="mt-1 text-xs text-slate-400">Checked {{ relative_time($bot->last_checked_at) }}</div>
                                        @endif
                                    </td>
                                    <td class="hidden text-xs text-slate-500 md:table-cell">
                                        {{ $bot->destinations_count }} {{ str('destination')->plural($bot->destinations_count) }}<br>
                                        {{ $bot->active_campaigns_count }} active {{ str('campaign')->plural($bot->active_campaigns_count) }}
                                    </td>
                                    <td class="hidden text-xs text-slate-500 lg:table-cell" title="{{ display_time($bot->last_activity_at) }}">{{ relative_time($bot->last_activity_at) }}</td>
                                    <td>
                                        <div class="flex items-center justify-end gap-1">
                                            @can('bots.manage')
                                                <button type="button" wire:click="test({{ $bot->id }})" wire:loading.attr="disabled" wire:target="test({{ $bot->id }})" class="btn btn-secondary btn-sm">
                                                    <span wire:loading.remove wire:target="test({{ $bot->id }})"><x-icon name="link" class="size-4" /></span>
                                                    <span wire:loading wire:target="test({{ $bot->id }})"><x-spinner /></span>
                                                    Test
                                                </button>
                                                <button type="button" wire:click="toggle({{ $bot->id }})" class="btn-icon" title="{{ $bot->isActive() ? 'Disable' : 'Enable' }}">
                                                    <x-icon :name="$bot->isActive() ? 'pause' : 'play'" class="size-4" />
                                                </button>
                                                <button type="button" wire:click="edit({{ $bot->id }})" class="btn-icon" title="Edit"><x-icon name="pencil" class="size-4" /></button>
                                                <x-confirm action="delete({{ $bot->id }})" class="btn-icon hover:text-red-600" title="Delete bot?"
                                                           :message="'Delete “'.$bot->name.'” and its '.$bot->destinations_count.' destination(s)? Posting history is kept.'" confirm-text="Delete">
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
                @if ($bots->hasPages())
                    <div class="border-t border-slate-200 px-4 py-3 dark:border-slate-800">{{ $bots->links() }}</div>
                @endif
            @endif
        </div>
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit bot' : 'Add bot'" max-width="lg">
        <form wire:submit="save" id="bot-form" class="space-y-4">
            <x-field label="Name" for="name" required hint="Internal name, e.g. “Marketing Bot”.">
                <input id="name" type="text" wire:model="name" class="input" maxlength="120" autofocus>
            </x-field>

            <x-field label="Bot token" for="token" :required="! $editingId"
                     :hint="$editingId ? 'Leave blank to keep the current token. Tokens are stored encrypted and never shown again.' : 'From @BotFather → /newbot. Stored encrypted; it is never displayed again.'">
                <input id="token" type="password" wire:model="token" class="input font-mono" autocomplete="off" spellcheck="false"
                       placeholder="{{ $editingId ? '•••••••••• (unchanged)' : '123456789:AAH…' }}">
            </x-field>

            <x-field label="Status" for="botStatus" error="botStatus">
                <select id="botStatus" wire:model="botStatus" class="input">
                    @foreach (\App\Enums\BotStatus::options() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </x-field>

            <x-field label="Description" for="description">
                <textarea id="description" wire:model="description" rows="3" class="input" maxlength="2000"></textarea>
            </x-field>

            <div class="rounded-lg bg-brand-50 p-3 text-xs text-brand-800 dark:bg-brand-500/10 dark:text-brand-200">
                When you save, the token is verified with Telegram (<span class="font-mono">getMe</span>) and the bot's @username is filled in automatically.
            </div>
        </form>

        <x-slot:footer>
            <button type="button" class="btn btn-secondary" x-on:click="show = false">Cancel</button>
            <button type="submit" form="bot-form" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading wire:target="save"><x-spinner /></span>
                Save & test connection
            </button>
        </x-slot:footer>
    </x-modal>
</div>
