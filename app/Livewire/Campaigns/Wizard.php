<?php

namespace App\Livewire\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\ContentType;
use App\Enums\FailureBehavior;
use App\Enums\PostingOrder;
use App\Enums\RepeatMode;
use App\Enums\TriggerType;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\Content;
use App\Models\Schedule;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Services\CampaignBuilder;
use App\Services\CampaignService;
use App\Services\ScheduleCalculator;
use App\Services\SettingsService;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * 10-step campaign creation / editing wizard.
 */
class Wizard extends Component
{
    use InteractsWithUi, WithPagination;

    public const STEPS = [
        1 => 'Campaign info',
        2 => 'Bot',
        3 => 'Destination',
        4 => 'Select content',
        5 => 'Arrange',
        6 => 'Trigger',
        7 => 'Schedule',
        8 => 'Retry & failure',
        9 => 'Review',
        10 => 'Activate',
    ];

    public const INTERVAL_PRESETS = [
        60 => 'Every 1 minute', 300 => 'Every 5 minutes', 600 => 'Every 10 minutes', 900 => 'Every 15 minutes',
        1800 => 'Every 30 minutes', 3600 => 'Every 1 hour', 7200 => 'Every 2 hours', 21600 => 'Every 6 hours',
        43200 => 'Every 12 hours', 86400 => 'Every 24 hours',
    ];

    #[Locked]
    public ?int $campaignId = null;

    public int $step = 1;

    public int $maxStep = 1;

    // Step 1
    public string $name = '';

    public string $description = '';

    // Steps 2-3
    public string $bot_id = '';

    public string $destination_id = '';

    public bool $test_mode = false;

    public string $test_destination_id = '';

    // Steps 4-5
    /** @var list<array{key: string, id: ?int, content_id: int, title: string, type: string, scheduled_at: string}> */
    public array $items = [];

    public string $contentSearch = '';

    public string $contentType = '';

    // Steps 6-7
    public string $trigger_type = 'interval';

    public string $posting_order = 'sequential';

    public string $timezone = 'Asia/Karachi';

    public string $start_date = '';

    public string $start_time = '';

    public string $end_date = '';

    public string $end_time = '';

    public string $interval_preset = '1800';

    public string $custom_interval_minutes = '37';

    /** @var list<array{time: string}> */
    public array $fixed_times = [];

    public string $daily_time = '10:00';

    public string $weekly_time = '19:00';

    /** @var list<int|string> */
    public array $weekly_days = [];

    // Step 8
    public string $repeat_mode = 'none';

    public string $repeat_limit = '';

    public string $failure_behavior = 'retry_then_continue';

    public string $max_retries = '3';

    public string $retry_delay_seconds = '60';

    public function mount(?Campaign $campaign = null): void
    {
        $this->authorize(Permissions::CAMPAIGNS_MANAGE);
        $settings = app(SettingsService::class);

        $this->timezone = (string) $settings->get('general.default_campaign_timezone');
        $this->max_retries = (string) $settings->get('telegram.default_max_retries');
        $this->retry_delay_seconds = (string) $settings->get('telegram.default_retry_delay');
        $this->fixed_times = [['time' => '09:00'], ['time' => '12:00'], ['time' => '15:00'], ['time' => '18:00'], ['time' => '21:00']];
        $this->weekly_days = [1, 3, 5];
        $this->bot_id = (string) (TelegramBot::query()->active()->count() === 1 ? TelegramBot::query()->active()->value('id') : '');

        if ($campaign?->exists) {
            if ($campaign->status === CampaignStatus::Running) {
                $this->flash('Pause the campaign before editing it.', 'warning');
                $this->redirectRoute('campaigns.show', $campaign, navigate: true);

                return;
            }

            $this->loadCampaign($campaign);
        }
    }

    private function loadCampaign(Campaign $campaign): void
    {
        $tz = $campaign->timezone;
        $this->campaignId = $campaign->id;
        $this->maxStep = 10;
        $this->fill([
            'name' => $campaign->name,
            'description' => (string) $campaign->description,
            'bot_id' => (string) $campaign->bot_id,
            'destination_id' => (string) $campaign->destination_id,
            'test_mode' => $campaign->test_mode,
            'test_destination_id' => (string) $campaign->test_destination_id,
            'trigger_type' => $campaign->trigger_type->value,
            'posting_order' => $campaign->posting_order->value,
            'timezone' => $tz,
            'start_date' => (string) $campaign->start_at?->setTimezone($tz)->format('Y-m-d'),
            'start_time' => (string) $campaign->start_at?->setTimezone($tz)->format('H:i'),
            'end_date' => (string) $campaign->end_at?->setTimezone($tz)->format('Y-m-d'),
            'end_time' => (string) $campaign->end_at?->setTimezone($tz)->format('H:i'),
            'repeat_mode' => $campaign->repeat_mode->value,
            'repeat_limit' => (string) $campaign->repeat_limit,
            'failure_behavior' => $campaign->failure_behavior->value,
            'max_retries' => (string) $campaign->max_retries,
            'retry_delay_seconds' => (string) $campaign->retry_delay_seconds,
        ]);

        if ($campaign->interval_seconds) {
            $this->interval_preset = array_key_exists($campaign->interval_seconds, self::INTERVAL_PRESETS) ? (string) $campaign->interval_seconds : 'custom';
            $this->custom_interval_minutes = (string) max(1, intdiv($campaign->interval_seconds, 60));
        }

        $schedules = $campaign->schedules()->get();

        if ($schedules->isNotEmpty()) {
            $times = $schedules->map(fn (Schedule $s) => substr((string) $s->time_of_day, 0, 5));
            match ($campaign->trigger_type) {
                TriggerType::FixedTime => $this->fixed_times = $times->map(fn ($t) => ['time' => $t])->values()->all(),
                TriggerType::Daily => $this->daily_time = $times->first(),
                TriggerType::Weekly => [$this->weekly_time, $this->weekly_days] = [$times->first(), $schedules->first()->weekdays()],
                default => null,
            };
        }

        $this->items = $campaign->items()->with('content:id,title,type,deleted_at')->get()
            ->map(fn (CampaignItem $item) => [
                'key' => (string) Str::uuid(),
                'id' => $item->id,
                'content_id' => $item->content_id,
                'title' => $item->content?->title ?? 'Deleted content',
                'type' => $item->content?->type->value ?? 'text',
                'scheduled_at' => (string) $item->scheduled_at?->setTimezone($tz)->format('Y-m-d\TH:i'),
            ])->values()->all();
    }

    // ------------------------------------------------------------------
    // Navigation
    // ------------------------------------------------------------------

    public function next(): void
    {
        if (! $this->validateStep($this->step)) {
            return;
        }

        $this->step = min(10, $this->step + 1);
        $this->maxStep = max($this->maxStep, $this->step);
    }

    public function back(): void
    {
        $this->resetValidation();
        $this->step = max(1, $this->step - 1);
    }

    public function goTo(int $step): void
    {
        if ($step < 1 || $step > $this->maxStep) {
            return;
        }

        // Moving forward requires every step in between to be valid.
        for ($s = $this->step; $s < $step; $s++) {
            if (! $this->validateStep($s)) {
                $this->step = $s;

                return;
            }
        }

        $this->resetValidation();
        $this->step = $step;
    }

    public function updatedBotId(): void
    {
        $this->destination_id = '';
        $this->test_destination_id = '';
    }

    public function updatedContentSearch(): void
    {
        $this->resetPage('contentPage');
    }

    public function updatedContentType(): void
    {
        $this->resetPage('contentPage');
    }

    // ------------------------------------------------------------------
    // Sequence editing
    // ------------------------------------------------------------------

    public function addContent(int $contentId): void
    {
        $content = Content::query()->find($contentId);

        if (! $content) {
            return;
        }

        $this->items[] = $this->itemRow($content);
        $this->resetValidation('items');
    }

    public function addAllVisible(): void
    {
        foreach ($this->contentQuery()->paginate(12, pageName: 'contentPage')->items() as $content) {
            $this->items[] = $this->itemRow($content);
        }

        $this->resetValidation('items');
    }

    /**
     * @return array{key: string, id: null, content_id: int, title: string, type: string, scheduled_at: string}
     */
    private function itemRow(Content $content): array
    {
        return [
            'key' => (string) Str::uuid(),
            'id' => null,
            'content_id' => $content->id,
            'title' => $content->title,
            'type' => $content->type->value,
            'scheduled_at' => '',
        ];
    }

    public function removeItem(string $key): void
    {
        $this->items = array_values(array_filter($this->items, fn ($i) => $i['key'] !== $key));
    }

    public function duplicateItem(string $key): void
    {
        $index = $this->indexOf($key);

        if ($index === null) {
            return;
        }

        $copy = array_merge($this->items[$index], ['key' => (string) Str::uuid(), 'id' => null]);
        array_splice($this->items, $index + 1, 0, [$copy]);
    }

    public function moveItem(string $key, int $delta): void
    {
        $index = $this->indexOf($key);

        if ($index !== null) {
            $this->reorder($key, $index + $delta);
        }
    }

    /**
     * Drag & drop handler (wire:sort).
     */
    public function reorder(string $key, int $position): void
    {
        $index = $this->indexOf($key);

        if ($index === null) {
            return;
        }

        $item = $this->items[$index];
        array_splice($this->items, $index, 1);
        $position = max(0, min($position, count($this->items)));
        array_splice($this->items, $position, 0, [$item]);
    }

    private function indexOf(string $key): ?int
    {
        foreach ($this->items as $i => $item) {
            if ($item['key'] === $key) {
                return $i;
            }
        }

        return null;
    }

    public function addFixedTime(): void
    {
        $this->fixed_times[] = ['time' => '12:00'];
    }

    public function removeFixedTime(int $index): void
    {
        unset($this->fixed_times[$index]);
        $this->fixed_times = array_values($this->fixed_times);
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    private function validateStep(int $step): bool
    {
        $this->resetValidation();

        $rules = match ($step) {
            1 => ['name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:2000']],
            2 => ['bot_id' => ['required', 'exists:telegram_bots,id']],
            3 => [
                'destination_id' => ['required', function ($a, $v, $fail) {
                    if (! TelegramDestination::query()->whereKey($v)->where('bot_id', $this->bot_id)->exists()) {
                        $fail('Choose a destination connected to the selected bot.');
                    }
                }],
                'test_destination_id' => [$this->test_mode ? 'required' : 'nullable', function ($a, $v, $fail) {
                    if ($this->test_mode && $v && ! TelegramDestination::query()->whereKey($v)->where('bot_id', $this->bot_id)->exists()) {
                        $fail('The test chat must be connected to the same bot.');
                    }
                }],
            ],
            4, 5 => ['items' => ['array', 'min:1']],
            6 => ['trigger_type' => ['required', 'in:'.implode(',', TriggerType::values())], 'posting_order' => ['required', 'in:'.implode(',', PostingOrder::values())]],
            7 => $this->scheduleRules(),
            8 => [
                'repeat_mode' => ['required', 'in:'.implode(',', RepeatMode::values())],
                'repeat_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
                'failure_behavior' => ['required', 'in:'.implode(',', FailureBehavior::values())],
                'max_retries' => ['required', 'integer', 'min:0', 'max:20'],
                'retry_delay_seconds' => ['required', 'integer', 'min:5', 'max:86400'],
            ],
            default => [],
        };

        $messages = ['items.min' => 'Add at least one content item to the sequence.', 'test_destination_id.required' => 'Select the test chat used while Test Mode is on.'];

        // Review / activate steps have nothing of their own to validate.
        if ($rules === []) {
            return true;
        }

        try {
            $this->validate($rules, $messages);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return false;
        }

        if ($step === 8 && $this->trigger_type === 'scheduled' && in_array($this->repeat_mode, ['immediate', 'indefinite'], true)) {
            $this->addError('repeat_mode', 'With exact per-item times, only “Stop”, “Repeat daily” or “Repeat weekly” are possible.');

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleRules(): array
    {
        $rules = [
            'timezone' => ['required', 'timezone:all'],
            'start_date' => ['nullable', 'date_format:Y-m-d', 'required_with:start_time'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'required_with:end_time'],
            'end_time' => ['nullable', 'date_format:H:i'],
        ];

        $rules += match ($this->trigger_type) {
            'interval' => $this->interval_preset === 'custom'
                ? ['custom_interval_minutes' => ['required', 'integer', 'min:1', 'max:44640']]
                : ['interval_preset' => ['required', 'in:'.implode(',', array_keys(self::INTERVAL_PRESETS)).',custom']],
            'fixed_time' => ['fixed_times' => ['array', 'min:1', 'max:48'], 'fixed_times.*.time' => ['required', 'date_format:H:i', 'distinct']],
            'daily' => ['daily_time' => ['required', 'date_format:H:i']],
            'weekly' => ['weekly_time' => ['required', 'date_format:H:i'], 'weekly_days' => ['array', 'min:1'], 'weekly_days.*' => ['integer', 'between:1,7']],
            'scheduled' => ['items.*.scheduled_at' => ['required', 'date']],
            default => [],
        };

        return $rules;
    }

    // ------------------------------------------------------------------
    // Save
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $combine = fn (string $date, string $time) => $date !== '' ? $date.' '.($time !== '' ? $time : '00:00') : null;

        $slots = match ($this->trigger_type) {
            'fixed_time' => array_values(array_map(fn ($t) => ['time' => $t['time']], $this->fixed_times)),
            'daily' => [['time' => $this->daily_time]],
            'weekly' => [['time' => $this->weekly_time, 'days' => array_map('intval', $this->weekly_days)]],
            default => [],
        };

        return [
            'name' => $this->name,
            'description' => $this->description ?: null,
            'bot_id' => (int) $this->bot_id,
            'destination_id' => (int) $this->destination_id,
            'test_mode' => $this->test_mode,
            'test_destination_id' => $this->test_destination_id !== '' ? (int) $this->test_destination_id : null,
            'posting_order' => $this->posting_order,
            'trigger_type' => $this->trigger_type,
            'timezone' => $this->timezone,
            'start_at' => $combine($this->start_date, $this->start_time),
            'end_at' => $combine($this->end_date, $this->end_time),
            'interval_seconds' => $this->trigger_type === 'interval' ? $this->intervalSeconds() : null,
            'repeat_mode' => $this->repeat_mode,
            'repeat_limit' => $this->repeat_limit !== '' ? (int) $this->repeat_limit : null,
            'failure_behavior' => $this->failure_behavior,
            'max_retries' => (int) $this->max_retries,
            'retry_delay_seconds' => (int) $this->retry_delay_seconds,
            'items' => array_map(fn ($i) => [
                'id' => $i['id'],
                'content_id' => $i['content_id'],
                'scheduled_at' => $this->trigger_type === 'scheduled' && $i['scheduled_at'] !== '' ? str_replace('T', ' ', $i['scheduled_at']) : null,
            ], $this->items),
            'slots' => $slots,
        ];
    }

    private function intervalSeconds(): int
    {
        return $this->interval_preset === 'custom' ? (int) $this->custom_interval_minutes * 60 : (int) $this->interval_preset;
    }

    public function save(bool $activate = false): void
    {
        $this->authorize(Permissions::CAMPAIGNS_MANAGE);

        foreach (array_keys(self::STEPS) as $s) {
            if ($s <= 8 && ! $this->validateStep($s)) {
                $this->step = $s;
                $this->toast('Please fix the highlighted fields.', 'error');

                return;
            }
        }

        $data = $this->payload();
        $validator = Validator::make($data, CampaignBuilder::rules());

        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());
            $this->toast($validator->errors()->first(), 'error');

            return;
        }

        $campaign = $this->campaignId ? Campaign::query()->findOrFail($this->campaignId) : null;
        $saved = null;

        if (! $this->attempt(function () use ($data, $campaign, &$saved) {
            $saved = app(CampaignBuilder::class)->save($data, $campaign, auth()->user());
        })) {
            return;
        }

        $this->campaignId = $saved->id;

        if ($activate) {
            $this->authorize(Permissions::CAMPAIGNS_OPERATE);

            if (! $this->attempt(fn () => app(CampaignService::class)->activate($saved, auth()->user()))) {
                $this->toast('Saved as draft, but it could not be activated: '.collect($this->getErrorBag()->all())->first(), 'warning');

                return;
            }

            $this->flash("Campaign “{$saved->name}” is now active.");
        } else {
            $this->flash("Campaign “{$saved->name}” saved.");
        }

        $this->redirectRoute('campaigns.show', $saved, navigate: true);
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    private function contentQuery()
    {
        return Content::query()
            ->active()
            ->search($this->contentSearch)
            ->when($this->contentType !== '', fn ($q) => $q->where('type', $this->contentType))
            ->latest();
    }

    /**
     * Unsaved campaign mirroring the form, used for the review step.
     */
    private function draftCampaign(): Campaign
    {
        $data = $this->payload();
        $campaign = new Campaign;
        $campaign->forceFill([
            'trigger_type' => TriggerType::from($this->trigger_type),
            'timezone' => $this->timezone,
            'interval_seconds' => $data['interval_seconds'],
            'start_at' => $data['start_at'] ? CarbonImmutable::parse($data['start_at'], $this->timezone)->utc() : null,
            'end_at' => $data['end_at'] ? CarbonImmutable::parse($data['end_at'], $this->timezone)->utc() : null,
        ]);
        $campaign->setRelation('schedules', collect($data['slots'])->map(fn ($slot) => new Schedule([
            'schedule_type' => $this->trigger_type,
            'time_of_day' => $slot['time'].':00',
            'days' => $slot['days'] ?? null,
            'timezone' => $this->timezone,
            'status' => 'active',
        ])));
        $campaign->setRelation('items', collect($data['items'])->map(fn ($i) => new CampaignItem([
            'scheduled_at' => $i['scheduled_at'] ? CarbonImmutable::parse($i['scheduled_at'], $this->timezone)->utc() : null,
        ])));

        return $campaign;
    }

    public function render()
    {
        $bots = TelegramBot::query()->withCount('destinations')->orderBy('name')->get();
        $botDestinations = $this->bot_id !== ''
            ? TelegramDestination::query()->where('bot_id', $this->bot_id)->orderBy('name')->get()
            : collect();

        $preview = [];
        $previewError = null;

        if ($this->step >= 9) {
            try {
                // Never preview more runs than will happen: once per item per cycle, cycles = 1 + repeats.
                $repeat = RepeatMode::from($this->repeat_mode);
                $cycles = match (true) {
                    $repeat === RepeatMode::None => 1,
                    $repeat->supportsLimit() && $this->repeat_limit !== '' => 1 + (int) $this->repeat_limit,
                    default => PHP_INT_MAX,
                };
                $runs = (int) min(6, count($this->items) * min($cycles, 6));
                $preview = $runs > 0 ? app(ScheduleCalculator::class)->preview($this->draftCampaign(), $runs) : [];
            } catch (\Throwable) {
                $previewError = 'Could not calculate run times — check the schedule.';
            }
        }

        $campaign = $this->campaignId ? Campaign::query()->find($this->campaignId) : null;

        return view('livewire.campaigns.wizard', [
            'steps' => self::STEPS,
            'bots' => $bots,
            'botDestinations' => $botDestinations,
            'selectedBot' => $bots->firstWhere('id', (int) $this->bot_id),
            'selectedDestination' => $botDestinations->firstWhere('id', (int) $this->destination_id),
            'selectedTestDestination' => $botDestinations->firstWhere('id', (int) $this->test_destination_id),
            'contents' => $this->step === 4 ? $this->contentQuery()->with('tags:id,name')->paginate(12, pageName: 'contentPage') : null,
            'previewRuns' => $preview,
            'previewError' => $previewError,
            'existing' => $campaign,
            'timezones' => \DateTimeZone::listIdentifiers(),
            'contentTypes' => ContentType::options(),
        ])->title($campaign ? 'Edit campaign' : 'New campaign');
    }
}
