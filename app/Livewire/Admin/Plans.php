<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Plan;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Subscription plans: price (USD / month), limits and features.
 */
#[Title('Plans')]
class Plans extends Component
{
    use InteractsWithUi;

    /** Limit columns edited as "blank = unlimited". */
    private const LIMITS = ['max_bots', 'max_channels', 'max_groups', 'max_campaigns', 'max_contents', 'posts_per_day', 'posts_per_week', 'posts_per_month', 'max_upload_mb'];

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        $this->authorize(Permissions::PLANS_MANAGE);
        $this->form = $this->blank();
    }

    /**
     * @return array<string, mixed>
     */
    private function blank(): array
    {
        return [
            'name' => '', 'description' => '', 'price_usd' => '', 'sort_order' => (int) Plan::query()->max('sort_order') + 1,
            'is_active' => true, 'is_featured' => false,
            'max_bots' => '1', 'max_channels' => '1', 'max_groups' => '1', 'max_campaigns' => '', 'max_contents' => '',
            'posts_per_day' => '', 'posts_per_week' => '', 'posts_per_month' => '',
            'min_interval_minutes' => '1', 'max_upload_mb' => '20',
            'allow_uploads' => true, 'allow_ai' => false, 'allow_api' => false, 'extra_features' => '',
        ];
    }

    public function create(): void
    {
        $this->authorize(Permissions::PLANS_MANAGE);
        $this->editingId = null;
        $this->form = $this->blank();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize(Permissions::PLANS_MANAGE);
        $plan = Plan::query()->findOrFail($id);
        $this->editingId = $plan->id;
        $this->form = collect($this->blank())->keys()->mapWithKeys(function (string $key) use ($plan) {
            $value = $plan->getAttribute($key);

            return [$key => is_bool($value) ? $value : (string) ($value ?? '')];
        })->all();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize(Permissions::PLANS_MANAGE);

        $limitRule = ['nullable', 'integer', 'min:0', 'max:10000000'];
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:80', function ($attribute, $value, $fail) {
                $exists = Plan::query()->where('slug', Str::slug($value))->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))->exists();

                if ($exists || Str::slug($value) === '') {
                    $fail($exists ? 'Another plan already has this name.' : 'Use letters or numbers in the name.');
                }
            }],
            'form.description' => ['nullable', 'string', 'max:255'],
            'form.price_usd' => ['required', 'numeric', 'min:0', 'max:100000'],
            'form.sort_order' => ['required', 'integer', 'min:0', 'max:1000'],
            'form.is_active' => ['boolean'],
            'form.is_featured' => ['boolean'],
            'form.max_bots' => $limitRule,
            'form.max_channels' => $limitRule,
            'form.max_groups' => $limitRule,
            'form.max_campaigns' => $limitRule,
            'form.max_contents' => $limitRule,
            'form.posts_per_day' => $limitRule,
            'form.posts_per_week' => $limitRule,
            'form.posts_per_month' => $limitRule,
            'form.min_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'form.max_upload_mb' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'form.allow_uploads' => ['boolean'],
            'form.allow_ai' => ['boolean'],
            'form.allow_api' => ['boolean'],
            'form.extra_features' => ['nullable', 'string', 'max:2000'],
        ], [], ['form.price_usd' => 'price', 'form.min_interval_minutes' => 'shortest interval'])['form'];

        foreach (self::LIMITS as $column) {
            $data[$column] = ($data[$column] ?? '') === '' ? null : (int) $data[$column];
        }

        $plan = $this->editingId ? Plan::query()->findOrFail($this->editingId) : new Plan;

        if ($plan->is_default && (float) $data['price_usd'] > 0) {
            $this->addError('form.price_usd', 'The default (free) plan must cost $0.');

            return;
        }

        $plan->fill($data);
        $plan->slug = Str::slug($data['name']);
        $plan->save();

        $audit->log($this->editingId ? 'plan.updated' : 'plan.created', $plan, "Plan “{$plan->name}” saved", $this->editingId ? $audit->changes($plan) : []);
        $this->showForm = false;
        $this->toast("Plan “{$plan->name}” saved.");
    }

    public function toggle(int $id, AuditLogger $audit): void
    {
        $this->authorize(Permissions::PLANS_MANAGE);
        $plan = Plan::query()->findOrFail($id);

        if ($plan->is_default && $plan->is_active) {
            $this->toast('The default plan cannot be hidden: new and expired accounts use it.', 'error');

            return;
        }

        $plan->update(['is_active' => ! $plan->is_active]);
        $audit->log('plan.updated', $plan, "Plan “{$plan->name}” ".($plan->is_active ? 'shown' : 'hidden'));
        $this->toast($plan->is_active ? 'Plan is available for purchase.' : 'Plan hidden. Accounts on it keep it until it expires.');
    }

    public function makeDefault(int $id, AuditLogger $audit): void
    {
        $this->authorize(Permissions::PLANS_MANAGE);
        $plan = Plan::query()->findOrFail($id);

        if (! $plan->isFree()) {
            $this->toast('Only a free ($0) plan can be the default plan.', 'error');

            return;
        }

        DB::transaction(function () use ($plan) {
            Plan::query()->where('is_default', true)->update(['is_default' => false]);
            $plan->update(['is_default' => true, 'is_active' => true]);
        });

        $audit->log('plan.updated', $plan, "Plan “{$plan->name}” is now the default plan");
        $this->toast("“{$plan->name}” is now the default plan for new and expired accounts.");
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $this->authorize(Permissions::PLANS_MANAGE);
        $plan = Plan::query()->withCount(['users', 'orders'])->findOrFail($id);

        if ($plan->is_default || $plan->users_count > 0 || $plan->orders_count > 0) {
            $this->toast('This plan is in use (default plan, accounts or purchase history). Hide it instead.', 'error');

            return;
        }

        $plan->delete();
        $audit->log('plan.deleted', $plan, "Plan “{$plan->name}” deleted");
        $this->toast('Plan deleted.');
    }

    public function render()
    {
        return view('livewire.admin.plans', [
            'plans' => Plan::query()->withCount('users')->orderBy('sort_order')->orderBy('price_usd')->get(),
            'limitLabels' => [
                'max_bots' => 'Bots', 'max_channels' => 'Channels', 'max_groups' => 'Groups', 'max_campaigns' => 'Campaigns',
                'max_contents' => 'Content items', 'posts_per_day' => 'Posts / day', 'posts_per_week' => 'Posts / week',
                'posts_per_month' => 'Posts / month', 'max_upload_mb' => 'Max upload (MB)',
            ],
        ]);
    }
}
