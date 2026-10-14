<div>
    <x-page-header title="Welcome DMs" description="Send a private welcome message to people who join your channels and groups." />

    <div class="mb-6 grid gap-4 lg:grid-cols-3">
        <div class="card p-5 lg:col-span-2">
            <h2 class="card-title">How it works</h2>
            <ol class="mt-3 list-decimal space-y-1.5 pl-5 text-sm text-slate-600 dark:text-slate-300">
                <li>Turn on the welcome for a channel or group and write your message (Edit).</li>
                <li>Press <strong>Create link</strong> to get a <strong>join-request invite link</strong> and share that link instead of your normal one.</li>
                <li>Everyone who joins with it gets your welcome DM right away. With auto-approve on, the bot also lets them in.</li>
            </ol>
        </div>
        <x-docs.callout type="warning" title="Telegram's rule">
            A bot may only message people who joined with a <strong>join-request link</strong>, or who pressed <strong>Start</strong> in the bot. People who join through a normal public link cannot be messaged by any bot.
            <span class="mt-1 block text-xs">Bot sirf unhein DM kar sakta hai jo join-request link se aayein ya jinhon ne bot ko Start kiya ho.</span>
        </x-docs.callout>
    </div>

    <div class="card">
        <div class="border-b border-slate-200 p-4 dark:border-slate-800">
            <div class="relative sm:max-w-sm">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search channels and groups…" class="input pl-9">
            </div>
        </div>

        @if ($destinations->isEmpty())
            <x-empty icon="megaphone" title="No channels or groups yet" description="Add a channel or group first, then set up its welcome message here.">
                <a href="{{ route('destinations.channels') }}" wire:navigate class="btn btn-primary"><x-icon name="plus" class="size-4" /> Add a channel</a>
            </x-empty>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Channel / group</th>
                            <th>Welcome</th>
                            <th class="hidden md:table-cell">Bot updates</th>
                            <th>Join-request link</th>
                            <th class="hidden lg:table-cell">Last 7 days</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($destinations as $destination)
                            @php
                                $bot = $destination->bot;
                                $s = $stats[$destination->id] ?? collect();
                            @endphp
                            <tr wire:key="w-{{ $destination->id }}">
                                <td>
                                    <div class="font-medium text-slate-900 dark:text-white">{{ $destination->name }}</div>
                                    <div class="text-xs text-slate-500"><x-badge :color="$destination->type->color()">{{ $destination->type->label() }}</x-badge> via {{ $bot?->name ?? 'deleted bot' }}</div>
                                </td>
                                <td>
                                    <x-badge :color="$destination->welcome_enabled ? 'green' : 'slate'">{{ $destination->welcome_enabled ? 'On' : 'Off' }}</x-badge>
                                    @if ($destination->auto_approve_requests)
                                        <x-badge color="blue">Auto-approve</x-badge>
                                    @endif
                                </td>
                                <td class="hidden md:table-cell">
                                    @if ($bot?->updates_connected_at)
                                        <x-badge color="green" dot>Connected</x-badge>
                                        <div class="mt-0.5 text-xs text-slate-400">{{ number_format($subscribers[$bot->id] ?? 0) }} started the bot</div>
                                    @elseif ($bot && ! $bot->trashed())
                                        @can('destinations.manage')
                                            <button type="button" wire:click="connect({{ $bot->id }})" wire:loading.attr="disabled" wire:target="connect({{ $bot->id }})" class="btn btn-secondary btn-sm">
                                                <span wire:loading wire:target="connect({{ $bot->id }})"><x-spinner /></span> Connect
                                            </button>
                                        @endcan
                                        @if ($bot->updates_error)
                                            <div class="mt-1 max-w-xs text-xs text-red-600 dark:text-red-400">{{ str($bot->updates_error)->limit(90) }}</div>
                                        @endif
                                    @endif
                                </td>
                                <td>
                                    @if ($destination->join_request_link)
                                        <div class="flex items-center gap-1" x-data="{ copied: false }">
                                            <span class="max-w-[12rem] truncate font-mono text-xs">{{ $destination->join_request_link }}</span>
                                            <button type="button" class="btn-icon" title="Copy link" x-on:click="navigator.clipboard.writeText(@js($destination->join_request_link)); copied = true; setTimeout(() => copied = false, 1500)">
                                                <x-icon name="copy" class="size-4" x-show="!copied" /><x-icon name="check" class="size-4 text-emerald-500" x-show="copied" x-cloak />
                                            </button>
                                        </div>
                                    @elseif ($bot && ! $bot->trashed())
                                        @can('destinations.manage')
                                            <button type="button" wire:click="createLink({{ $destination->id }})" wire:loading.attr="disabled" wire:target="createLink({{ $destination->id }})" class="btn btn-secondary btn-sm">
                                                <span wire:loading wire:target="createLink({{ $destination->id }})"><x-spinner /></span> Create link
                                            </button>
                                        @endcan
                                    @endif
                                </td>
                                <td class="hidden text-xs lg:table-cell">
                                    <span class="text-emerald-600">{{ number_format($s['sent'] ?? 0) }} sent</span>
                                    @if (($s['failed'] ?? 0) > 0)
                                        · <a href="{{ route('logs.index', ['status' => 'failed']) }}" wire:navigate class="text-red-600 hover:underline">{{ number_format($s['failed']) }} failed</a>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @can('destinations.manage')
                                        <button type="button" wire:click="edit({{ $destination->id }})" class="btn btn-secondary btn-sm"><x-icon name="pencil" class="size-4" /> Edit</button>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <x-modal wire:model="showForm" :title="$editing ? 'Welcome DM · '.$editing->name : 'Welcome DM'" max-width="3xl">
        @if ($editing)
            <form wire:submit="save" id="welcome-form" class="grid gap-6 md:grid-cols-5">
                <div class="space-y-4 md:col-span-3">
                    <label class="flex items-center gap-2 text-sm font-medium">
                        <input type="checkbox" wire:model.live="form.welcome_enabled" class="checkbox"> Send a welcome DM to new members
                    </label>

                    <x-field label="Welcome message" for="form.welcome_message" :required="$form['welcome_enabled'] ?? false">
                        <textarea id="form.welcome_message" wire:model.live.debounce.500ms="form.welcome_message" rows="6" class="input" maxlength="4000"></textarea>
                    </x-field>
                    <div class="flex flex-wrap gap-1.5 text-xs">
                        <span class="text-slate-500">Insert:</span>
                        @foreach (\App\Services\WelcomeService::PLACEHOLDERS as $tag => $label)
                            <button type="button" class="rounded bg-slate-100 px-1.5 py-0.5 font-mono hover:bg-brand-50 dark:bg-slate-800" title="{{ $label }}"
                                    x-on:click="$wire.set('form.welcome_message', ($wire.form.welcome_message || '') + '{{ $tag }}')">{{ $tag }}</button>
                        @endforeach
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field label="Formatting" for="form.welcome_parse_mode">
                            <select id="form.welcome_parse_mode" wire:model.live="form.welcome_parse_mode" class="input">
                                <option value="">Plain text</option>
                                @foreach (\App\Enums\ParseMode::options() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </x-field>
                        <div></div>
                        <x-field label="Button text (optional)" for="form.welcome_button_text">
                            <input id="form.welcome_button_text" type="text" wire:model="form.welcome_button_text" class="input" maxlength="64" placeholder="e.g. Visit our website">
                        </x-field>
                        <x-field label="Button link" for="form.welcome_button_url">
                            <input id="form.welcome_button_url" type="url" wire:model="form.welcome_button_url" class="input" placeholder="https://…">
                        </x-field>
                    </div>

                    <div class="space-y-2 text-sm">
                        <label class="flex items-start gap-2"><input type="checkbox" wire:model="form.auto_approve_requests" class="checkbox mt-0.5">
                            <span>Approve join requests automatically <span class="block text-xs text-slate-500">After the welcome DM, the bot lets the person in. The bot needs the “Invite users via link” admin right.</span></span></label>
                        <label class="flex items-start gap-2"><input type="checkbox" wire:model="form.welcome_direct_joins" class="checkbox mt-0.5">
                            <span>Also welcome people who join directly <span class="block text-xs text-slate-500">Only works for people who have pressed Start in the bot; Telegram blocks the others.</span></span></label>
                    </div>
                </div>

                <div class="md:col-span-2">
                    <div class="label">Preview (for “Ali Khan”)</div>
                    <div class="rounded-2xl bg-[#e6ebee] p-3 dark:bg-slate-800">
                        <div class="whitespace-pre-line break-words rounded-xl rounded-tl-sm bg-white p-3 text-sm text-slate-800 shadow-sm dark:bg-slate-700 dark:text-slate-100">{{ $preview }}</div>
                        @if (($form['welcome_button_text'] ?? '') !== '')
                            <div class="mt-1 rounded-lg bg-white/80 py-1.5 text-center text-sm font-medium text-brand-600 dark:bg-slate-700">{{ $form['welcome_button_text'] }} ↗</div>
                        @endif
                    </div>
                    <p class="mt-2 text-xs text-slate-500">Welcome DMs count towards your plan's posting limit and appear in Posting Logs.</p>
                </div>
            </form>
        @endif
        <x-slot:footer>
            <button type="button" class="btn btn-secondary" x-on:click="show = false">Cancel</button>
            <button type="submit" form="welcome-form" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save"><span wire:loading wire:target="save"><x-spinner /></span> Save</button>
        </x-slot:footer>
    </x-modal>
</div>
