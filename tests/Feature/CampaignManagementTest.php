<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\ItemStatus;
use App\Enums\PostTrigger;
use App\Jobs\ProcessCampaignJob;
use App\Livewire\Campaigns\Show;
use App\Livewire\Campaigns\Wizard;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\TelegramDestination;
use App\Models\User;
use App\Services\CampaignBuilder;
use App\Services\CampaignService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CampaignManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(TelegramDestination $destination, array $contentIds, array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Daily Videos',
            'bot_id' => $destination->bot_id,
            'destination_id' => $destination->id,
            'posting_order' => 'sequential',
            'trigger_type' => 'interval',
            'timezone' => 'Asia/Karachi',
            'start_at' => '2026-10-10 09:00',
            'interval_seconds' => 1800,
            'repeat_mode' => 'none',
            'failure_behavior' => 'retry_then_continue',
            'max_retries' => 3,
            'retry_delay_seconds' => 60,
            'items' => array_map(fn ($id) => ['content_id' => $id], $contentIds),
            'slots' => [],
        ];
    }

    #[Test]
    public function the_builder_creates_a_campaign_with_its_sequence(): void
    {
        $destination = TelegramDestination::factory()->create();
        $contents = Content::factory()->count(3)->create();

        $campaign = app(CampaignBuilder::class)->save($this->payload($destination, $contents->pluck('id')->all()));

        $this->assertSame(CampaignStatus::Draft, $campaign->status);
        $this->assertSame(1, $campaign->cycle);
        $this->assertSame([1, 2, 3], $campaign->items()->pluck('sort_order')->all());
        $this->assertSame(3, $campaign->items()->where('cycle', 1)->where('status', ItemStatus::Pending)->count());
        // Wall-clock 09:00 in Karachi is stored as 04:00 UTC.
        $this->assertSame('2026-10-10 04:00:00', $campaign->start_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_destination_must_belong_to_the_selected_bot(): void
    {
        $destination = TelegramDestination::factory()->create();
        $other = TelegramDestination::factory()->create();

        $this->expectException(ValidationException::class);
        app(CampaignBuilder::class)->save($this->payload($destination, [Content::factory()->create()->id], ['destination_id' => $other->id]));
    }

    #[Test]
    public function the_wizard_creates_and_activates_a_campaign(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-10 08:00', 'Asia/Karachi'));
        $destination = TelegramDestination::factory()->create();
        [$a, $b] = Content::factory()->count(2)->create()->all();
        $this->actingAs(User::factory()->admin()->create());

        $component = Livewire::test(Wizard::class)
            ->set('name', 'Wizard Campaign')->call('next')
            ->set('bot_id', (string) $destination->bot_id)->call('next')
            ->set('destination_id', (string) $destination->id)->call('next')
            ->call('next')->assertHasErrors('items') // step 4 requires content
            ->call('addContent', $a->id)->call('addContent', $b->id)->call('next')
            ->call('next')                            // arrange
            ->set('trigger_type', 'interval')->call('next')
            ->set('interval_preset', 'custom')->set('custom_interval_minutes', '37')
            ->set('start_date', '2026-10-10')->set('start_time', '09:00')->call('next')
            ->call('next')                            // retry settings
            ->call('next')                            // review
            ->assertSet('step', 10)
            ->call('save', true)
            ->assertHasNoErrors();

        $campaign = Campaign::query()->sole();
        $component->assertRedirect(route('campaigns.show', $campaign));

        $this->assertSame(CampaignStatus::Scheduled, $campaign->status);
        $this->assertSame(37 * 60, $campaign->interval_seconds);
        $this->assertSame('09:00', $campaign->next_run_at->setTimezone('Asia/Karachi')->format('H:i'));
        $this->assertSame([$a->id, $b->id], $campaign->items()->pluck('content_id')->all());
    }

    #[Test]
    public function the_review_step_previews_only_the_runs_that_will_happen(): void
    {
        $destination = TelegramDestination::factory()->create();
        $content = Content::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        $wizard = Livewire::test(Wizard::class)
            ->set('name', 'Every minute')->call('next')
            ->set('bot_id', (string) $destination->bot_id)->call('next')
            ->set('destination_id', (string) $destination->id)->call('next')
            ->call('addContent', $content->id)->call('next')
            ->call('next')
            ->set('trigger_type', 'interval')->call('next')
            ->set('interval_preset', '60')->call('next')
            ->set('repeat_mode', 'immediate')->set('repeat_limit', '4')->call('next');

        // 1 item × (1 + 4 repeats) = 5 posts.
        $wizard->assertViewHas('previewRuns', fn (array $runs) => count($runs) === 5);

        $wizard->set('repeat_mode', 'none')->assertViewHas('previewRuns', fn (array $runs) => count($runs) === 1);
    }

    #[Test]
    public function the_wizard_reorders_items_by_drag_and_drop(): void
    {
        [$a, $b, $c] = Content::factory()->count(3)->create()->all();
        $this->actingAs(User::factory()->admin()->create());

        $component = Livewire::test(Wizard::class)
            ->call('addContent', $a->id)->call('addContent', $b->id)->call('addContent', $c->id);

        $keyOfC = $component->get('items')[2]['key'];
        $component->call('reorder', $keyOfC, 0);

        $this->assertSame([$c->id, $a->id, $b->id], array_column($component->get('items'), 'content_id'));
    }

    #[Test]
    public function the_details_page_can_pause_resume_and_run_now(): void
    {
        Queue::fake();
        $campaign = Campaign::factory()->withItems(2)->create();
        app(CampaignService::class)->activate($campaign);
        $this->actingAs(User::factory()->admin()->create());

        $page = Livewire::test(Show::class, ['campaign' => $campaign]);

        $page->call('pause');
        $this->assertSame(CampaignStatus::Paused, $campaign->refresh()->status);

        $page->call('resume');
        $this->assertSame(CampaignStatus::Running, $campaign->refresh()->status);

        $page->call('runNow');
        Queue::assertPushed(ProcessCampaignJob::class, fn ($job) => $job->campaignId === $campaign->id && $job->trigger === PostTrigger::Manual);

        foreach (['campaign.paused', 'campaign.resumed', 'campaign.run_now'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'entity_id' => $campaign->id]);
        }
    }

    #[Test]
    public function the_sequence_can_be_reordered_on_the_details_page(): void
    {
        $campaign = Campaign::factory()->withItems(3)->create();
        $items = $campaign->items()->get();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Show::class, ['campaign' => $campaign])->call('reorder', $items[2]->id, 0);

        $this->assertSame([$items[2]->id, $items[0]->id, $items[1]->id], $campaign->items()->pluck('id')->all());
        $this->assertSame([1, 2, 3], $campaign->items()->pluck('sort_order')->all());
    }

    #[Test]
    public function run_now_is_refused_for_draft_campaigns(): void
    {
        Queue::fake();
        $campaign = Campaign::factory()->withItems(1)->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Show::class, ['campaign' => $campaign])->call('runNow')->assertDispatched('toast', type: 'error');
        Queue::assertNothingPushed();
    }

    #[Test]
    public function activation_is_blocked_when_the_campaign_is_not_ready(): void
    {
        $campaign = Campaign::factory()->create(); // no items
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Show::class, ['campaign' => $campaign])->call('activate')->assertDispatched('toast', type: 'error');
        $this->assertSame(CampaignStatus::Draft, $campaign->refresh()->status);
    }
}
