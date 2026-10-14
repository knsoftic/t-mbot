<div @if ($polling) wire:poll.3s @endif>
    <x-page-header title="Member import" description="Upload a list of Telegram usernames or user IDs for a group or channel. Members are recognised, reachable people are invited, and listed people are let in when they ask to join." />

    <div class="grid gap-6 lg:grid-cols-5">
        {{-- New import --}}
        <form wire:submit="start" class="card space-y-4 p-5 lg:col-span-3">
            <h2 class="card-title">New import</h2>

            <x-field label="Group / channel" for="destination_id" required>
                <select id="destination_id" wire:model="destination_id" class="input">
                    <option value="">Choose…</option>
                    @foreach ($destinations as $d)
                        <option value="{{ $d->id }}">{{ $d->name }} ({{ $d->type->label() }}, {{ $d->bot?->name }})</option>
                    @endforeach
                </select>
            </x-field>

            <x-field label="List of people" for="file" required hint="CSV, TXT or Excel (.xlsx), up to 10,000 people. One per row: @username, t.me/username or numeric user ID. A header row with “username” / “user_id” columns is recognised.">
                <input id="file" type="file" wire:model="file" accept=".csv,.txt,.xlsx" class="block w-full text-sm">
                <div wire:loading wire:target="file" class="mt-1 text-xs text-slate-500"><x-spinner /> Uploading…</div>
                <a href="{{ route('member-imports.sample') }}" class="mt-1 inline-block text-xs font-medium text-brand-600 hover:underline">Download a sample CSV</a>
            </x-field>

            <div class="space-y-2 text-sm">
                <label class="flex items-start gap-2"><input type="checkbox" wire:model.live="send_invites" class="checkbox mt-0.5">
                    <span>Send a private invite to people who started the bot <span class="block text-xs text-slate-500">Only they can receive a message from a bot. Counts towards your plan's posting limit.</span></span></label>
                <label class="flex items-start gap-2"><input type="checkbox" wire:model="auto_approve" class="checkbox mt-0.5">
                    <span>Approve join requests from people on this list <span class="block text-xs text-slate-500">They join with the chat's join-request link; requests from anyone else stay pending.</span></span></label>
            </div>

            @if ($send_invites)
                <x-field label="Invite message" for="invite_message" hint="{first_name}, {chat_title} and {link} are filled in for each person.">
                    <textarea id="invite_message" wire:model="invite_message" rows="5" class="input" maxlength="3000"></textarea>
                </x-field>
            @endif

            <div class="flex justify-end border-t border-slate-100 pt-4 dark:border-slate-800">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="start,file">
                    <span wire:loading wire:target="start"><x-spinner /></span>
                    <x-icon name="upload" class="size-4" wire:loading.remove wire:target="start" /> Upload & start
                </button>
            </div>
        </form>

        <div class="space-y-4 lg:col-span-2">
            <x-docs.callout type="warning" title="What Telegram allows">
                A bot <strong>cannot add anyone</strong> to a group or channel. Adding people in bulk with personal accounts breaks Telegram's rules and gets accounts banned, so this tool never does it.
                <span class="mt-1 block">Instead, everyone gets in by <strong>asking to join</strong> with your join-request link — and only people on your list are approved automatically.</span>
            </x-docs.callout>
            <div class="card p-5 text-sm">
                <div class="font-semibold text-slate-900 dark:text-white">What happens to each person</div>
                <ul class="mt-2 space-y-1.5 text-slate-600 dark:text-slate-300">
                    @foreach (\App\Enums\ImportRowStatus::cases() as $case)
                        @continue($case === \App\Enums\ImportRowStatus::Pending)
                        <li class="flex gap-2"><x-badge :color="$case->color()" class="shrink-0">{{ $case->label() }}</x-badge>
                            <span class="text-xs">{{ match ($case) {
                                \App\Enums\ImportRowStatus::Added => 'Their join request was approved, or they joined.',
                                \App\Enums\ImportRowStatus::Invited => 'Started the bot, so it sent them your invite.',
                                \App\Enums\ImportRowStatus::Waiting => 'Cannot be reached by a bot. Share the link; their request is approved.',
                                \App\Enums\ImportRowStatus::AlreadyMember => 'Already in the chat.',
                                \App\Enums\ImportRowStatus::Skipped => 'Invalid, duplicate, phone number or banned.',
                                \App\Enums\ImportRowStatus::Failed => 'Telegram returned an error (see the reason).',
                                default => '',
                            } }}</span></li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    {{-- Past imports --}}
    <div class="card mt-6">
        <div class="card-header"><h2 class="card-title">Imports</h2></div>
        @if ($imports->isEmpty())
            <x-empty icon="users" title="No imports yet" description="Upload your first list above." />
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>#</th><th>Chat</th><th>File</th><th>Progress</th><th class="hidden md:table-cell">Result</th><th class="hidden lg:table-cell">Started</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($imports as $import)
                            @php
                                $c = $counts[$import->id];
                                $total = array_sum($c);
                                $done = $total - $c['pending'];
                            @endphp
                            <tr wire:key="imp-{{ $import->id }}">
                                <td class="tabular-nums text-slate-500">{{ $import->id }}</td>
                                <td class="font-medium">{{ $import->destination?->name }}</td>
                                <td class="max-w-[12rem] truncate text-xs text-slate-500">{{ $import->file_name }}</td>
                                <td class="min-w-[9rem]">
                                    <div class="flex items-center gap-2 text-xs">
                                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-brand-500" style="width: {{ $total ? round($done / $total * 100) : 0 }}%"></div></div>
                                        <span class="tabular-nums text-slate-500">{{ number_format($done) }}/{{ number_format($total) }}</span>
                                    </div>
                                    @if ($import->status === \App\Models\MemberImport::FAILED)
                                        <div class="mt-0.5 text-xs text-red-600">Stopped</div>
                                    @elseif ($import->isRunning())
                                        <div class="mt-0.5 text-xs text-brand-600">Checking…</div>
                                    @endif
                                </td>
                                <td class="hidden text-xs md:table-cell">
                                    <span class="text-emerald-600">{{ $c['added'] }} added</span> ·
                                    <span class="text-brand-600">{{ $c['invited'] }} invited</span> ·
                                    <span class="text-amber-600">{{ $c['waiting'] }} waiting</span> ·
                                    <span class="text-slate-500">{{ $c['skipped'] + $c['already_member'] }} skipped</span>
                                    @if ($c['failed']) · <span class="text-red-600">{{ $c['failed'] }} failed</span> @endif
                                </td>
                                <td class="hidden whitespace-nowrap text-xs text-slate-500 lg:table-cell">{{ display_time($import->created_at, 'd M, H:i') }}</td>
                                <td class="text-right"><a href="{{ route('member-imports.show', $import) }}" wire:navigate class="btn btn-secondary btn-sm">Report</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($imports->hasPages())
                <div class="border-t border-slate-200 px-4 py-3 dark:border-slate-800">{{ $imports->links() }}</div>
            @endif
        @endif
    </div>
</div>
