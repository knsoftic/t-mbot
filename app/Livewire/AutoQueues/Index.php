<?php

namespace App\Livewire\AutoQueues;

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\AutoQueue;
use App\Models\AutoQueuePost;
use App\Models\Tag;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Services\AuditLogger;
use App\Services\AutoQueueService;
use App\Services\SettingsService;
use App\Support\Permissions;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Set a channel and posting times once; the Content Library posts itself.
 */
#[Title('Auto Queue')]
class Index extends Component
{
    use InteractsWithUi;

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        $this->authorize(Permissions::CAMPAIGNS_VIEW);
    }

    /**
     * @return array<string, mixed>
     */
    private function blankForm(): array
    {
        $bots = TelegramBot::query()->active()->pluck('id');

        return [
            'name' => 'Daily posts',
            'bot_id' => $bots->count() === 1 ? (string) $bots->first() : '',
            'destination_id' => '',
            'timezone' => auth()->user()->timezone ?: (string) app(SettingsService::class)->get('general.default_campaign_timezone'),
            'mode' => AutoQueue::TIMES,
            'interval_value' => '30',
            'interval_unit' => 'minutes',
            'active_from' => '',
            'active_until' => '',
            'times' => ['09:00', '14:00', '20:00'],
            'days' => ['1', '2', '3', '4', '5', '6', '7'],
            'tag_id' => '',
            'order' => 'oldest',
        ];
    }

    public function create(): void
    {
        $this->authorize(Permissions::CAMPAIGNS_MANAGE);
        $this->editingId = null;
        $this->form = $this->blankForm();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Permissions::CAMPAIGNS_MANAGE);
        $queue = AutoQueue::query()->findOrFail($id);
        $this->editingId = $queue->id;
        $this->form = [
            'name' => $queue->name,
            'bot_id' => (string) $queue->bot_id,
            'destination_id' => (string) $queue->destination_id,
            'timezone' => $queue->timezone,
            'mode' => $queue->mode,
            ...self::splitInterval((int) $queue->interval_seconds),
            'active_from' => (string) $queue->active_from,
            'active_until' => (string) $queue->active_until,
            'times' => $queue->times ?: ['09:00'],
            'days' => array_map('strval', $queue->days ?? [1, 2, 3, 4, 5, 6, 7]),
            'tag_id' => (string) $queue->tag_id,
            'order' => $queue->order,
        ];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function updatedFormBotId(): void
    {
        $this->form['destination_id'] = '';
    }

    /** Seconds per interval unit. */
    private const UNITS = ['seconds' => 1, 'minutes' => 60, 'hours' => 3600];

    /**
     * Quick buttons: key => [label, mode, seconds].
     *
     * @var array<string, array{0: string, 1: string, 2: int}>
     */
    public const PRESETS = [
        '10s' => ['Every 10 sec', 'interval', 10],
        '30s' => ['Every 30 sec', 'interval', 30],
        '1m' => ['Every minute', 'interval', 60],
        '5m' => ['Every 5 min', 'interval', 300],
        '15m' => ['Every 15 min', 'interval', 900],
        '30m' => ['Every 30 min', 'interval', 1800],
        '1h' => ['Every hour', 'interval', 3600],
        '6h' => ['Every 6 hours', 'interval', 21600],
        'daily' => ['Every day 09:00', 'times', 0],
    ];

    public function preset(string $key): void
    {
        if (! isset(self::PRESETS[$key])) {
            return;
        }

        [, $mode, $seconds] = self::PRESETS[$key];
        $this->form['mode'] = $mode;

        if ($mode === AutoQueue::INTERVAL) {
            $this->form = array_merge($this->form, self::splitInterval($seconds));
        } else {
            $this->form['times'] = ['09:00'];
            $this->form['days'] = ['1', '2', '3', '4', '5', '6', '7'];
        }

        $this->resetValidation();
    }

    /**
     * 1800 → 30 minutes, 7200 → 2 hours, 45 → 45 seconds.
     *
     * @return array{interval_value: string, interval_unit: string}
     */
    private static function splitInterval(int $seconds): array
    {
        $seconds = $seconds > 0 ? $seconds : 1800;
        $unit = match (true) {
            $seconds % 3600 === 0 => 'hours',
            $seconds % 60 === 0 => 'minutes',
            default => 'seconds',
        };

        return ['interval_value' => (string) intdiv($seconds, self::UNITS[$unit]), 'interval_unit' => $unit];
    }

    public function addTime(): void
    {
        if (count($this->form['times']) < AutoQueueService::MAX_TIMES) {
            $this->form['times'][] = '12:00';
        }
    }

    public function removeTime(int $index): void
    {
        unset($this->form['times'][$index]);
        $this->form['times'] = array_values($this->form['times']);
    }

    public function save(AutoQueueService $queues): void
    {
        $this->authorize(Permissions::CAMPAIGNS_MANAGE);

        $rules = collect(AutoQueueService::rules())->except('interval_seconds')->mapWithKeys(fn ($rule, $key) => ["form.{$key}" => $rule])->all();
        $rules['form.interval_value'] = ['nullable', 'required_if:form.mode,interval', 'integer', 'min:1', 'max:100000'];
        $rules['form.interval_unit'] = ['required', 'in:'.implode(',', array_keys(self::UNITS))];
        $data = $this->validate($rules, [
            'form.times.min' => 'Add at least one posting time.',
            'form.times.*.distinct' => 'Each posting time can be used once.',
        ], [
            'form.bot_id' => 'bot', 'form.destination_id' => 'channel / group', 'form.times.*' => 'time', 'form.tag_id' => 'tag',
        ])['form'];

        $data['interval_seconds'] = (int) ($data['interval_value'] ?? 0) * self::UNITS[$data['interval_unit']];
        $queue = $this->editingId ? AutoQueue::query()->findOrFail($this->editingId) : null;

        try {
            $saved = $queues->save($data, $queue, auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'interval_seconds' ? 'form.interval_value' : "form.{$field}", $messages[0]);
            }

            return;
        }

        $this->showForm = false;
        $this->toast($saved->next_run_at
            ? "“{$saved->name}” saved. Next post: ".display_time($saved->next_run_at, 'D d M, H:i').'.'
            : "“{$saved->name}” saved.");
    }

    public function toggle(int $id, AutoQueueService $queues): void
    {
        $this->authorize(Permissions::CAMPAIGNS_OPERATE);
        $queue = AutoQueue::query()->findOrFail($id);
        $queue->isActive() ? $queues->pause($queue, auth()->user()) : $queues->resume($queue, auth()->user());
        $this->toast($queue->isActive() ? 'Auto Queue resumed.' : 'Auto Queue paused. Nothing is posted until you resume it.');
    }

    public function postNow(int $id, AutoQueueService $queues): void
    {
        $this->authorize(Permissions::CAMPAIGNS_OPERATE);
        $queue = AutoQueue::query()->findOrFail($id);
        $this->attempt(fn () => $queues->runSlot($queue->id, manual: true), 'The next item is being posted now. The regular times stay the same.');
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $this->authorize(Permissions::CAMPAIGNS_MANAGE);
        $queue = AutoQueue::query()->findOrFail($id);
        $queue->delete();
        $audit->log('auto_queue.deleted', $queue, "Auto Queue “{$queue->name}” deleted");
        $this->toast('Auto Queue deleted. Posts already sent stay in the posting logs.');
    }

    public function render()
    {
        $queues = app(AutoQueueService::class);
        $list = AutoQueue::query()->with(['bot:id,name,username,status,deleted_at', 'destination:id,name,type,status,deleted_at', 'tag:id,name'])->latest('id')->get();

        $details = $list->mapWithKeys(fn (AutoQueue $queue) => [$queue->id => [
            'total' => $queues->eligible($queue)->count(),
            'remaining' => $queues->remaining($queue)->count(),
            'upcoming' => collect($queues->upcomingSlots($queue, 5))->zip($queues->upcomingContents($queue, 5))->map(fn ($pair) => $pair->all()),
            'recent' => AutoQueuePost::query()->with(['content:id,title', 'log:id,status,error_message,sent_at'])->where('auto_queue_id', $queue->id)->latest('id')->limit(5)->get(),
        ]]);

        return view('livewire.auto-queues.index', [
            'queues' => $list,
            'details' => $details,
            'bots' => TelegramBot::query()->orderBy('name')->get(['id', 'name', 'username', 'status']),
            'destinations' => ($this->form['bot_id'] ?? '') !== ''
                ? TelegramDestination::query()->where('bot_id', $this->form['bot_id'])->orderBy('name')->get(['id', 'name', 'type'])
                : collect(),
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name']),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }
}
