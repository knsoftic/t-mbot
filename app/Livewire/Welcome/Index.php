<?php

namespace App\Livewire\Welcome;

use App\Enums\PostTrigger;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\BotSubscriber;
use App\Models\PostingLog;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Services\WelcomeService;
use App\Support\Permissions;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Welcome DMs for new members of each channel / group.
 */
#[Title('Welcome DMs')]
class Index extends Component
{
    use InteractsWithUi;

    #[Url(except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        $this->authorize(Permissions::DESTINATIONS_VIEW);
    }

    public function edit(int $id): void
    {
        $this->authorize(Permissions::DESTINATIONS_MANAGE);
        $destination = TelegramDestination::query()->findOrFail($id);

        $this->editingId = $destination->id;
        $this->form = [
            'welcome_enabled' => $destination->welcome_enabled,
            'welcome_message' => $destination->welcome_message ?: WelcomeService::DEFAULT_MESSAGE,
            'welcome_parse_mode' => (string) $destination->welcome_parse_mode?->value,
            'welcome_button_text' => (string) $destination->welcome_button_text,
            'welcome_button_url' => (string) $destination->welcome_button_url,
            'welcome_direct_joins' => $destination->welcome_direct_joins,
            'auto_approve_requests' => $destination->auto_approve_requests,
        ];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(WelcomeService $welcome): void
    {
        $this->authorize(Permissions::DESTINATIONS_MANAGE);

        $rules = collect(WelcomeService::rules('form.'))->mapWithKeys(fn ($rule, $key) => ["form.{$key}" => $rule])->all();
        $data = $this->validate($rules, [
            'form.welcome_message.required_if' => 'Write the welcome message (or turn welcome DMs off).',
        ], [
            'form.welcome_message' => 'welcome message', 'form.welcome_button_text' => 'button text', 'form.welcome_button_url' => 'button link',
        ])['form'];

        $destination = TelegramDestination::query()->findOrFail($this->editingId);

        try {
            $welcome->saveSettings($destination, $data, auth()->user());
        } catch (ValidationException $e) {
            // Settings are saved; only connecting the bot failed.
            $this->showForm = false;
            $this->toast('Saved, but the bot could not start receiving new members: '.collect($e->errors())->flatten()->first(), 'warning');

            return;
        }

        $this->showForm = false;
        $this->toast("Welcome settings for “{$destination->name}” saved.");
    }

    public function connect(int $botId, WelcomeService $welcome): void
    {
        $this->authorize(Permissions::DESTINATIONS_MANAGE);
        $bot = TelegramBot::query()->findOrFail($botId);
        $this->attempt(fn () => $welcome->connect($bot), "“{$bot->name}” now receives new members and join requests.");
    }

    public function createLink(int $id, WelcomeService $welcome): void
    {
        $this->authorize(Permissions::DESTINATIONS_MANAGE);
        $destination = TelegramDestination::query()->findOrFail($id);
        $this->attempt(fn () => $welcome->createJoinLink($destination, auth()->user()), 'Join-request link created. Share it instead of your normal invite link.');
    }

    public function render()
    {
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

        $destinations = TelegramDestination::query()
            ->with('bot:id,name,username,status,updates_connected_at,updates_error,deleted_at')
            ->when(trim($this->search) !== '', fn ($q) => $q->where('name', 'like', $term))
            ->orderByDesc('welcome_enabled')->orderBy('name')
            ->get();

        $stats = PostingLog::query()
            ->where('trigger', PostTrigger::Welcome)
            ->where('created_at', '>=', now()->subDays(7))
            ->toBase()
            ->selectRaw('destination_id, status, count(*) as aggregate')
            ->groupBy('destination_id', 'status')
            ->get()
            ->groupBy('destination_id')
            ->map(fn ($rows) => $rows->pluck('aggregate', 'status'));

        $editing = $this->showForm && $this->editingId ? $destinations->firstWhere('id', $this->editingId) : null;
        $preview = null;

        if ($editing) {
            $draft = $editing->replicate();
            $draft->fill([
                'welcome_message' => $this->form['welcome_message'] ?? '',
                'welcome_parse_mode' => ($this->form['welcome_parse_mode'] ?? '') ?: null,
            ]);
            $preview = app(WelcomeService::class)->render($draft, ['first_name' => 'Ali', 'last_name' => 'Khan', 'username' => 'ali_khan']);
        }

        return view('livewire.welcome.index', [
            'destinations' => $destinations,
            'stats' => $stats,
            'subscribers' => BotSubscriber::query()->whereNull('blocked_at')->toBase()->selectRaw('bot_id, count(*) as aggregate')->groupBy('bot_id')->pluck('aggregate', 'bot_id'),
            'editing' => $editing,
            'preview' => $preview,
        ]);
    }
}
