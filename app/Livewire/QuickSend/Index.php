<?php

namespace App\Livewire\QuickSend;

use App\Enums\PostTrigger;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\PostingLog;
use App\Models\QuickSendTarget;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Services\AuditLogger;
use App\Services\QuickSendService;
use App\Support\Permissions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Type a message, press Send: it goes to the saved bot → channel/group at once.
 */
#[Title('Quick Send')]
class Index extends Component
{
    use InteractsWithUi;

    #[Url(as: 'target', except: null)]
    public ?int $targetId = null;

    public string $message = '';

    /** The message just sent, followed until Telegram answers. */
    #[Locked]
    public ?int $lastLogId = null;

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array{name: string, bot_id: string, destination_id: string, parse_mode: string, disable_notification: bool, disable_web_page_preview: bool, protect_content: bool} */
    public array $form = [];

    public function mount(): void
    {
        $this->authorize(Permissions::QUICK_SEND);
        $this->form = $this->blankForm();

        if ($this->targetId === null || ! QuickSendTarget::query()->whereKey($this->targetId)->exists()) {
            $this->targetId = QuickSendTarget::query()->orderByDesc('last_used_at')->orderBy('name')->value('id');
        }
    }

    public function select(int $id): void
    {
        $this->targetId = QuickSendTarget::query()->whereKey($id)->value('id');
        $this->lastLogId = null;
        $this->resetValidation('message');
    }

    public function send(QuickSendService $quickSend): void
    {
        $this->authorize(Permissions::QUICK_SEND);
        $this->validate(['message' => ['required', 'string', 'max:'.QuickSendService::MAX_LENGTH]], [], ['message' => 'message']);

        $key = 'quick-send:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, 30)) {
            $this->addError('message', 'Too many messages in a minute. Wait '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        $target = QuickSendTarget::query()->find($this->targetId);

        if (! $target) {
            $this->addError('message', 'Choose where to send the message first.');

            return;
        }

        RateLimiter::hit($key, 60);
        $log = null;

        if ($this->attempt(function () use ($quickSend, $target, &$log) {
            $log = $quickSend->send($target, $this->message, auth()->user());
        })) {
            $this->lastLogId = $log->id;
            $this->message = '';
            $this->dispatch('quick-send-sent');
        }
    }

    // ------------------------------------------------------------------
    // Targets
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function blankForm(): array
    {
        return [
            'name' => '', 'bot_id' => (string) (TelegramBot::query()->active()->count() === 1 ? TelegramBot::query()->active()->value('id') : ''),
            'destination_id' => '', 'parse_mode' => '', 'disable_notification' => false, 'disable_web_page_preview' => false, 'protect_content' => false,
        ];
    }

    public function create(): void
    {
        $this->authorize(Permissions::QUICK_SEND);
        $this->editingId = null;
        $this->form = $this->blankForm();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Permissions::QUICK_SEND);
        $target = QuickSendTarget::query()->findOrFail($id);
        $this->editingId = $target->id;
        $this->form = [
            'name' => $target->name,
            'bot_id' => (string) $target->bot_id,
            'destination_id' => (string) $target->destination_id,
            'parse_mode' => (string) $target->parse_mode?->value,
            'disable_notification' => $target->disable_notification,
            'disable_web_page_preview' => $target->disable_web_page_preview,
            'protect_content' => $target->protect_content,
        ];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function updatedFormBotId(): void
    {
        $this->form['destination_id'] = '';
    }

    public function updatedFormDestinationId(string $value): void
    {
        // Suggest a name from the chosen chat.
        if (trim($this->form['name']) === '' && $value !== '') {
            $this->form['name'] = (string) TelegramDestination::query()->whereKey($value)->value('name');
        }
    }

    public function saveTarget(QuickSendService $quickSend): void
    {
        $this->authorize(Permissions::QUICK_SEND);

        $rules = collect(QuickSendService::targetRules())->mapWithKeys(fn ($rule, $key) => ["form.{$key}" => $rule])->all();
        $data = $this->validate($rules, [], [
            'form.name' => 'name', 'form.bot_id' => 'bot', 'form.destination_id' => 'channel / group', 'form.parse_mode' => 'formatting',
        ])['form'];

        $target = $this->editingId ? QuickSendTarget::query()->findOrFail($this->editingId) : null;

        try {
            $saved = $quickSend->saveTarget($data, $target, auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError("form.{$field}", $messages[0]);
            }

            return;
        }

        $this->showForm = false;
        $this->targetId = $saved->id;
        $this->lastLogId = null;
        $this->toast("Target “{$saved->name}” saved. Type a message and press Send.");
    }

    public function deleteTarget(int $id, AuditLogger $audit): void
    {
        $this->authorize(Permissions::QUICK_SEND);
        $target = QuickSendTarget::query()->findOrFail($id);
        $target->delete();
        $audit->log('quick_send.target_deleted', $target, "Quick Send target “{$target->name}” deleted");

        if ($this->targetId === $id) {
            $this->targetId = QuickSendTarget::query()->orderByDesc('last_used_at')->value('id');
            $this->lastLogId = null;
        }

        $this->toast('Target deleted. Sent messages stay in the posting logs.');
    }

    public function render()
    {
        $targets = QuickSendTarget::query()
            ->with(['bot:id,name,username,status,deleted_at', 'destination:id,name,type,connection_status,status,deleted_at'])
            ->orderByDesc('last_used_at')->orderBy('name')
            ->get();

        $target = $targets->firstWhere('id', $this->targetId);
        $lastLog = $this->lastLogId ? PostingLog::query()->find($this->lastLogId) : null;

        return view('livewire.quick-send.index', [
            'targets' => $targets,
            'target' => $target,
            'lastLog' => $lastLog,
            'recent' => $target
                ? PostingLog::query()->where('quick_send_target_id', $target->id)->where('trigger', PostTrigger::QuickSend)->latest('id')->limit(10)->get()
                : collect(),
            'bots' => TelegramBot::query()->orderBy('name')->get(['id', 'name', 'username', 'status']),
            'formDestinations' => ($this->form['bot_id'] ?? '') !== ''
                ? TelegramDestination::query()->where('bot_id', $this->form['bot_id'])->orderBy('name')->get(['id', 'name', 'type', 'connection_status'])
                : collect(),
        ]);
    }
}
