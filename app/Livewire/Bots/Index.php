<?php

namespace App\Livewire\Bots;

use App\Enums\BotStatus;
use App\Enums\ConnectionStatus;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\TelegramBot;
use App\Services\BotService;
use App\Support\Permissions;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Bots')]
class Index extends Component
{
    use InteractsWithUi, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    // Write-only: never filled from the database, so the token never reaches the browser.
    public string $token = '';

    public string $botStatus = 'active';

    public string $description = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->authorize(Permissions::BOTS_MANAGE);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Permissions::BOTS_MANAGE);
        $bot = TelegramBot::query()->findOrFail($id);

        $this->resetForm();
        $this->editingId = $bot->id;
        $this->name = $bot->name;
        $this->botStatus = $bot->status->value;
        $this->description = (string) $bot->description;
        $this->showForm = true;
    }

    public function save(BotService $bots): void
    {
        $this->authorize(Permissions::BOTS_MANAGE);
        $bot = $this->editingId ? TelegramBot::query()->findOrFail($this->editingId) : null;

        $rules = BotService::rules($bot);
        $rules['botStatus'] = $rules['status'];
        unset($rules['status']);

        $data = $this->validate($rules, BotService::messages(), ['botStatus' => 'status']);
        $data['status'] = $data['botStatus'];

        $saved = null;
        $ok = $this->attempt(function () use ($bots, $bot, $data, &$saved) {
            $saved = $bot ? $bots->update($bot, $data, auth()->user()) : $bots->create($data, auth()->user());
        });

        if (! $ok) {
            return;
        }

        $this->showForm = false;
        $this->resetForm();

        if ($saved->connection_status === ConnectionStatus::Connected) {
            $this->toast("Bot saved and connected as @{$saved->username}.");
        } elseif ($saved->connection_status === ConnectionStatus::Failed) {
            $this->toast('Bot saved, but the connection test failed: '.$saved->last_error, 'warning');
        } else {
            $this->toast('Bot saved.');
        }
    }

    public function test(int $id, BotService $bots): void
    {
        $this->authorize(Permissions::BOTS_MANAGE);

        if (RateLimiter::tooManyAttempts('bot-test:'.auth()->id(), 20)) {
            $this->toast('Too many tests. Please wait a minute.', 'warning');

            return;
        }

        RateLimiter::hit('bot-test:'.auth()->id());
        $bot = TelegramBot::query()->findOrFail($id);
        $result = $bots->test($bot);

        $this->toast(($result['ok'] ? 'Connected — ' : 'Connection failed — ').$result['message'], $result['ok'] ? 'success' : 'error');
    }

    public function toggle(int $id, BotService $bots): void
    {
        $this->authorize(Permissions::BOTS_MANAGE);
        $bot = TelegramBot::query()->findOrFail($id);
        $new = $bot->isActive() ? BotStatus::Disabled : BotStatus::Active;
        $bots->setStatus($bot, $new, auth()->user());
        $this->toast("Bot \"{$bot->name}\" ".($new === BotStatus::Active ? 'enabled' : 'disabled').'.');
    }

    public function delete(int $id, BotService $bots): void
    {
        $this->authorize(Permissions::BOTS_MANAGE);
        $bot = TelegramBot::query()->findOrFail($id);
        $this->attempt(fn () => $bots->delete($bot, auth()->user()), "Bot \"{$bot->name}\" deleted.");
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'token', 'description']);
        $this->botStatus = BotStatus::Active->value;
        $this->resetValidation();
    }

    public function render()
    {
        $bots = TelegramBot::query()
            ->withCount([
                'destinations',
                'campaigns as active_campaigns_count' => fn ($q) => $q->whereIn('status', ['scheduled', 'running', 'paused']),
            ])
            ->when($this->search !== '', function ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('username', 'like', $like));
            })
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest()
            ->paginate(15);

        return view('livewire.bots.index', ['bots' => $bots]);
    }
}
