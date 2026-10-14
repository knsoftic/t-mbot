<div @if ($import->isRunning()) wire:poll.2s @endif>
    <x-page-header :title="'Member import #'.$import->id" :description="($import->destination?->name ?? 'Deleted chat').' · '.$import->file_name.' · '.display_time($import->created_at, 'd M Y, H:i')">
        <x-slot:actions>
            <a href="{{ route('member-imports.index') }}" wire:navigate class="btn btn-secondary"><x-icon name="chevron-left" class="size-4" /> All imports</a>
            <a href="{{ route('member-imports.export', $import) }}" class="btn btn-secondary"><x-icon name="document" class="size-4" /> Export CSV</a>
            @if (! $import->isRunning() && ($counts['waiting'] + $counts['failed'] + $counts['pending']) > 0)
                <button type="button" wire:click="recheck" class="btn btn-primary" wire:loading.attr="disabled" wire:target="recheck"><x-icon name="refresh" class="size-4" /> Check again</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- Progress --}}
    <div class="card p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2 text-sm font-medium">
                @if ($import->isRunning())
                    <x-spinner class="size-4 text-brand-600" /> Checking members… {{ number_format($done) }} of {{ number_format($total) }}
                @elseif ($import->status === \App\Models\MemberImport::FAILED)
                    <x-icon name="x-circle" class="size-5 text-red-600" /> Stopped: <span class="font-normal text-red-700 dark:text-red-300">{{ $import->error }}</span>
                @else
                    <x-icon name="check-circle" class="size-5 text-emerald-600" /> Finished {{ display_time($import->finished_at, 'd M, H:i') }} — {{ number_format($total) }} people checked
                @endif
            </div>
            <div class="text-xs text-slate-500">
                {{ $import->send_invites ? 'Invites on' : 'Invites off' }} · {{ $import->auto_approve ? 'auto-approve on' : 'auto-approve off' }}
            </div>
        </div>
        <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
            <div class="h-full rounded-full bg-brand-500 transition-all" style="width: {{ $total ? round($done / $total * 100) : 0 }}%"></div>
        </div>

        <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
            @foreach (\App\Enums\ImportRowStatus::cases() as $case)
                <button type="button" wire:click="$set('status', '{{ $status === $case->value ? '' : $case->value }}')" @class([
                    'rounded-xl p-3 text-left ring-1 transition',
                    'ring-2 ring-brand-500' => $status === $case->value,
                    'ring-slate-200 hover:ring-brand-300 dark:ring-slate-700' => $status !== $case->value,
                ])>
                    <div class="text-xs text-slate-500">{{ $case->label() }}</div>
                    <div class="mt-0.5 text-xl font-semibold tabular-nums text-slate-900 dark:text-white">{{ number_format($counts[$case->value]) }}</div>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Join-request link --}}
    @if ($import->invite_link)
        <div class="card mt-4 flex flex-wrap items-center gap-3 p-4" x-data="{ copied: false }">
            <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold text-slate-900 dark:text-white">Join-request link</div>
                <div class="text-xs text-slate-500">Share it with the people marked “Waiting for request” (e-mail, WhatsApp, your website…). @if ($import->auto_approve) Their requests are approved automatically; requests from anyone else stay pending. @endif</div>
            </div>
            <span class="truncate font-mono text-sm">{{ $import->invite_link }}</span>
            <button type="button" class="btn btn-secondary btn-sm" x-on:click="navigator.clipboard.writeText(@js($import->invite_link)); copied = true; setTimeout(() => copied = false, 1500)">
                <x-icon name="copy" class="size-4" /> <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
            </button>
        </div>
    @endif

    {{-- People --}}
    <div class="card mt-4">
        <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center dark:border-slate-800">
            <div class="relative flex-1 sm:max-w-sm">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search username or ID…" class="input pl-9">
            </div>
            <select wire:model.live="status" class="input sm:w-52">
                <option value="">All statuses</option>
                @foreach (\App\Enums\ImportRowStatus::options() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @if ($rows->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-slate-500">Nobody matches this filter.</p>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Row</th><th>Person</th><th>Status</th><th>Reason</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr wire:key="r-{{ $row->id }}">
                                <td class="tabular-nums text-xs text-slate-500">{{ $row->row_number }}</td>
                                <td>
                                    <div class="font-medium">{{ $row->display() }}</div>
                                    @if ($row->username && $row->telegram_user_id)<div class="text-xs text-slate-500">ID {{ $row->telegram_user_id }}</div>@endif
                                </td>
                                <td><x-badge :color="$row->status->color()">{{ $row->status->label() }}</x-badge></td>
                                <td class="max-w-md text-xs text-slate-600 dark:text-slate-300">{{ $row->reason ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($rows->hasPages())
                <div class="border-t border-slate-200 px-4 py-3 dark:border-slate-800">{{ $rows->links() }}</div>
            @endif
        @endif
    </div>
</div>
