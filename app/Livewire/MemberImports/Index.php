<?php

namespace App\Livewire\MemberImports;

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\MemberImport;
use App\Models\TelegramDestination;
use App\Services\MemberImportService;
use App\Services\WelcomeService;
use App\Support\Accounts;
use App\Support\Permissions;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Upload a list of Telegram users for a channel / group, and past imports.
 */
#[Title('Member import')]
class Index extends Component
{
    use InteractsWithUi, WithFileUploads, WithPagination;

    public string $destination_id = '';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public bool $send_invites = true;

    public bool $auto_approve = true;

    public string $invite_message = MemberImportService::DEFAULT_INVITE;

    public function mount(): void
    {
        $this->authorize(Permissions::MEMBERS_IMPORT);
    }

    public function start(MemberImportService $imports, WelcomeService $welcome): void
    {
        $this->authorize(Permissions::MEMBERS_IMPORT);

        $this->validate([
            'destination_id' => ['required', 'integer', Accounts::exists('telegram_destinations')],
            'file' => ['required', 'file', 'extensions:csv,txt,xlsx', 'max:5120'],
            'invite_message' => ['nullable', 'string', 'max:3000'],
        ], [
            'file.extensions' => 'Upload a .csv, .txt or .xlsx file.',
        ], ['destination_id' => 'channel / group', 'file' => 'file']);

        $destination = TelegramDestination::query()->with('bot')->findOrFail($this->destination_id);

        // Approving join requests needs the bot to receive them.
        $warning = null;

        if ($this->auto_approve && $destination->bot && ! $destination->bot->updates_connected_at) {
            try {
                $welcome->connect($destination->bot);
            } catch (ValidationException $e) {
                $warning = collect($e->errors())->flatten()->first();
            }
        }

        $import = null;

        if (! $this->attempt(function () use ($imports, $destination, &$import) {
            $import = $imports->create($destination, $this->file, [
                'send_invites' => $this->send_invites,
                'auto_approve' => $this->auto_approve,
                'invite_message' => $this->invite_message,
            ], auth()->user());
        })) {
            return;
        }

        $this->flash($warning
            ? "List uploaded, but join requests cannot be approved yet: {$warning}"
            : 'List uploaded. Members are being checked now.', $warning ? 'warning' : 'success');
        $this->redirectRoute('member-imports.show', $import, navigate: true);
    }

    public function render()
    {
        $imports = MemberImport::query()->with('destination:id,name,type')->latest('id')->paginate(15);
        $counts = $imports->getCollection()->mapWithKeys(fn (MemberImport $import) => [$import->id => $import->counts()]);

        return view('livewire.member-imports.index', [
            'imports' => $imports,
            'counts' => $counts,
            'destinations' => TelegramDestination::query()->with('bot:id,name')->orderBy('type')->orderBy('name')->get(['id', 'name', 'type', 'bot_id']),
            'polling' => $imports->getCollection()->contains(fn (MemberImport $import) => $import->isRunning()),
        ]);
    }
}
