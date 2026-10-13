<?php

namespace App\Livewire\Destinations;

use App\Enums\ConnectionStatus;
use App\Enums\DestinationStatus;
use App\Enums\DestinationType;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Services\DestinationService;
use App\Support\Permissions;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Channels, groups and supergroups. The sidebar "Channels" and "Groups"
 * entries open this page pre-filtered by type.
 */
class Index extends Component
{
    use InteractsWithUi, WithPagination;

    #[Locked]
    public string $scope = 'all';

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'bot', except: '')]
    public string $botFilter = '';

    #[Url(except: '')]
    public string $status = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        $this->scope = match (request()->route()?->getName()) {
            'destinations.channels' => 'channel',
            'destinations.groups' => 'group',
            default => 'all',
        };
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'botFilter', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->authorize(Permissions::DESTINATIONS_MANAGE);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Permissions::DESTINATIONS_MANAGE);
        $destination = TelegramDestination::query()->findOrFail($id);

        $this->resetForm();
        $this->editingId = $destination->id;
        $this->form = [
            'bot_id' => (string) $destination->bot_id,
            'name' => $destination->name,
            'chat_id' => $destination->chat_id,
            'type' => $destination->type->value,
            'status' => $destination->status->value,
            'is_test_chat' => $destination->is_test_chat,
            'description' => (string) $destination->description,
        ];
        $this->showForm = true;
    }

    public function save(DestinationService $destinations): void
    {
        $this->authorize(Permissions::DESTINATIONS_MANAGE);
        $destination = $this->editingId ? TelegramDestination::query()->findOrFail($this->editingId) : null;

        $rules = collect(DestinationService::rules($destination))->mapWithKeys(fn ($r, $k) => ["form.{$k}" => $r])->all();
        $messages = collect(DestinationService::messages())->mapWithKeys(fn ($m, $k) => ["form.{$k}" => $m])->all();
        $attributes = ['form.bot_id' => 'bot', 'form.chat_id' => 'chat ID', 'form.name' => 'name', 'form.type' => 'type'];
        $data = $this->validate($rules, $messages, $attributes)['form'];
        $data['is_test_chat'] = (bool) ($data['is_test_chat'] ?? false);

        $saved = null;
        $ok = $this->attempt(function () use ($destinations, $destination, $data, &$saved) {
            $saved = $destination
                ? $destinations->update($destination, $data, auth()->user())
                : $destinations->create($data, auth()->user());
        });

        if (! $ok) {
            // Service errors are keyed without the "form." prefix; mirror them onto the inputs.
            foreach (['chat_id', 'bot_id'] as $key) {
                if ($this->getErrorBag()->has($key)) {
                    $this->addError("form.{$key}", $this->getErrorBag()->first($key));
                }
            }

            return;
        }

        $this->showForm = false;
        $this->toast("“{$saved->name}” saved. Click Test to verify the bot can post there.");
    }

    public function test(int $id, bool $sendMessage = true): void
    {
        $destinations = app(DestinationService::class);
        $this->authorize(Permissions::DESTINATIONS_MANAGE);

        if (RateLimiter::tooManyAttempts('destination-test:'.auth()->id(), 20)) {
            $this->toast('Too many tests. Please wait a minute.', 'warning');

            return;
        }

        RateLimiter::hit('destination-test:'.auth()->id());
        $destination = TelegramDestination::query()->findOrFail($id);
        $destinations->queueTest($destination, $sendMessage, auth()->user());

        $this->toast($sendMessage ? 'Test message queued — the result appears here in a few seconds.' : 'Permission check queued.', 'info');
    }

    public function delete(int $id, DestinationService $destinations): void
    {
        $this->authorize(Permissions::DESTINATIONS_MANAGE);
        $destination = TelegramDestination::query()->findOrFail($id);
        $this->attempt(fn () => $destinations->delete($destination, auth()->user()), "“{$destination->name}” deleted.");
    }

    private function resetForm(): void
    {
        $this->reset(['editingId']);
        $this->resetValidation();
        $this->form = [
            'bot_id' => (string) (TelegramBot::query()->active()->orderBy('name')->value('id') ?? ''),
            'name' => '',
            'chat_id' => '',
            'type' => $this->scope === 'group' ? DestinationType::Supergroup->value : DestinationType::Channel->value,
            'status' => DestinationStatus::Active->value,
            'is_test_chat' => false,
            'description' => '',
        ];
    }

    public function render()
    {
        $destinations = TelegramDestination::query()
            ->with('bot:id,name,username,deleted_at')
            ->withCount(['campaigns as active_campaigns_count' => fn ($q) => $q->whereIn('status', ['scheduled', 'running', 'paused'])])
            ->when($this->scope === 'channel', fn ($q) => $q->where('type', DestinationType::Channel))
            ->when($this->scope === 'group', fn ($q) => $q->whereIn('type', DestinationType::groupTypes()))
            ->when($this->search !== '', function ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('chat_id', 'like', $like)
                    ->orWhere('username', 'like', $like)->orWhere('telegram_title', 'like', $like));
            })
            ->when($this->botFilter !== '', fn ($q) => $q->where('bot_id', $this->botFilter))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest()
            ->paginate(15);

        $title = match ($this->scope) {
            'channel' => 'Channels',
            'group' => 'Groups',
            default => 'Channels & Groups',
        };

        return view('livewire.destinations.index', [
            'destinations' => $destinations,
            'bots' => TelegramBot::query()->orderBy('name')->get(['id', 'name', 'username', 'status']),
            'pageTitle' => $title,
            'polling' => $destinations->contains(fn ($d) => $d->connection_status === ConnectionStatus::Testing),
        ])->title($title);
    }
}
