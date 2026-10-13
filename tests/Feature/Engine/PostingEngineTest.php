<?php

namespace Tests\Feature\Engine;

use App\Enums\CampaignStatus;
use App\Enums\ConnectionStatus;
use App\Enums\FailureBehavior;
use App\Enums\ItemStatus;
use App\Enums\PostingOrder;
use App\Enums\PostingStatus;
use App\Enums\PostTrigger;
use App\Enums\RepeatMode;
use App\Enums\TriggerType;
use App\Jobs\Middleware\TelegramRateLimit;
use App\Jobs\SendTelegramMessageJob;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentBotFile;
use App\Models\PostingLog;
use App\Models\Schedule;
use App\Models\TelegramDestination;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\PostingService;
use App\Services\RecoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeTelegram;
use Tests\TestCase;

class PostingEngineTest extends TestCase
{
    use RefreshDatabase;

    private FakeTelegram $telegram;

    protected function setUp(): void
    {
        parent::setUp();
        $this->telegram = FakeTelegram::install();
    }

    private function at(string $karachiTime): void
    {
        $this->travelTo(CarbonImmutable::parse($karachiTime, 'Asia/Karachi'));
    }

    private function tick(): void
    {
        $this->artisan('campaigns:dispatch-due')->assertSuccessful();
    }

    private function service(): CampaignService
    {
        return app(CampaignService::class);
    }

    /**
     * @return list<Content>
     */
    private function sampleContents(): array
    {
        return [
            Content::factory()->video('https://cdn.example.org/v1.mp4')->create(['title' => 'Video 1']),
            Content::factory()->video('https://cdn.example.org/v2.mp4')->create(['title' => 'Video 2']),
            Content::factory()->image('https://cdn.example.org/i1.jpg')->create(['title' => 'Image 1']),
            Content::factory()->create(['title' => 'Text 1', 'text' => 'Hello channel']),
            Content::factory()->video('https://cdn.example.org/v3.mp4')->create(['title' => 'Video 3']),
        ];
    }

    private function intervalCampaign(array $attributes = [], ?array $contents = null): Campaign
    {
        return Campaign::factory()->withItems($contents ?? $this->sampleContents())->create($attributes + [
            'trigger_type' => TriggerType::Interval,
            'interval_seconds' => 1800,
            'start_at' => CarbonImmutable::parse('2026-10-10 09:00', 'Asia/Karachi')->utc(),
        ]);
    }

    #[Test]
    public function the_sample_campaign_posts_one_item_every_30_minutes_then_completes(): void
    {
        $this->at('2026-10-10 08:00');
        $campaign = $this->service()->activate($this->intervalCampaign());

        $this->assertSame(CampaignStatus::Scheduled, $campaign->status);
        $this->assertSame('09:00', $campaign->next_run_at->setTimezone('Asia/Karachi')->format('H:i'));

        $this->tick();
        $this->telegram->assertSentCount(0);

        $expected = [
            '09:00' => 'sendVideo',
            '09:30' => 'sendVideo',
            '10:00' => 'sendPhoto',
            '10:30' => 'sendMessage',
            '11:00' => 'sendVideo',
        ];

        foreach ($expected as $time => $method) {
            $this->at("2026-10-10 {$time}");
            $this->tick();
            $this->assertSame($method, $this->telegram->lastSend()['method'], "Wrong post at {$time}");

            // A second tick in the same slot must not post again.
            $this->tick();
        }

        $this->telegram->assertSentCount(5);

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Completed, $campaign->status);
        $this->assertNull($campaign->next_run_at);
        $this->assertSame(5, $campaign->items()->where('status', ItemStatus::Sent)->count());
        $this->assertSame(5, PostingLog::query()->where('status', PostingStatus::Sent)->whereNotNull('telegram_message_id')->count());

        $this->assertSame(
            ['Video 1', 'Video 2', 'Image 1', 'Text 1', 'Video 3'],
            PostingLog::query()->orderBy('id')->with('content')->get()->pluck('content.title')->all(),
        );
    }

    #[Test]
    public function pause_stops_posting_and_resume_continues_with_the_next_pending_item(): void
    {
        $this->at('2026-10-10 09:00');
        $campaign = $this->service()->activate($this->intervalCampaign());
        $this->tick();
        $this->at('2026-10-10 09:30');
        $this->tick();
        $this->telegram->assertSentCount(2);

        $this->service()->pause($campaign);

        $this->at('2026-10-10 10:00');
        $this->tick();
        $this->at('2026-10-10 10:30');
        $this->tick();
        $this->telegram->assertSentCount(2);

        $this->at('2026-10-10 10:45');
        $campaign = $this->service()->resume($campaign);
        $this->assertSame(CampaignStatus::Running, $campaign->status);
        $this->tick();

        $this->telegram->assertSentCount(3);
        $this->assertSame('sendPhoto', $this->telegram->lastSend()['method'], 'Resume must continue with item #3 (Image 1), not restart');

        // The interval continues from the resume time.
        $this->assertSame('11:15', $campaign->refresh()->next_run_at->setTimezone('Asia/Karachi')->format('H:i'));
    }

    #[Test]
    public function run_now_posts_the_next_item_immediately_and_reschedules(): void
    {
        $this->at('2026-10-10 09:00');
        $campaign = $this->service()->activate($this->intervalCampaign());
        $this->tick();

        $this->at('2026-10-10 09:10');
        $this->service()->runNow($campaign, User::factory()->superAdmin()->create());

        $this->telegram->assertSentCount(2);
        $this->assertSame('09:40', $campaign->refresh()->next_run_at->setTimezone('Asia/Karachi')->format('H:i'));
        $this->assertSame(PostTrigger::Manual, PostingLog::query()->latest('id')->first()->trigger);
        $this->assertDatabaseHas('audit_logs', ['action' => 'campaign.run_now', 'entity_id' => $campaign->id]);
    }

    #[Test]
    public function manual_campaigns_only_post_on_run_now(): void
    {
        $this->at('2026-10-10 09:00');
        $campaign = $this->service()->activate($this->intervalCampaign(['trigger_type' => TriggerType::Manual, 'interval_seconds' => null, 'start_at' => null]));

        $this->assertNull($campaign->next_run_at);
        $this->at('2026-10-11 09:00');
        $this->tick();
        $this->telegram->assertSentCount(0);

        $this->service()->runNow($campaign);
        $this->service()->runNow($campaign);
        $this->telegram->assertSentCount(2);
    }

    #[Test]
    public function an_item_is_never_sent_twice_when_two_workers_handle_the_same_attempt(): void
    {
        Queue::fake();
        $this->at('2026-10-10 09:00');
        $campaign = $this->service()->activate($this->intervalCampaign(contents: [Content::factory()->create()]));

        $this->assertSame('dispatched', $this->service()->process($campaign->id, PostTrigger::Schedule));
        Queue::assertPushed(SendTelegramMessageJob::class, 1);

        // A second scheduler tick / Run Now while the item is in flight claims nothing.
        $this->assertSame('busy', $this->service()->process($campaign->id, PostTrigger::Manual));
        $this->assertSame(1, PostingLog::query()->count());

        $log = PostingLog::query()->sole();
        $posting = app(PostingService::class);

        // Two workers receive the same job (e.g. queue redelivery): only one calls Telegram.
        $this->assertSame('sent', $posting->deliver($log->id));
        $this->assertSame('stale', $posting->deliver($log->id));
        $this->telegram->assertSentCount(1);
    }

    #[Test]
    public function the_api_call_guard_blocks_a_concurrent_worker(): void
    {
        Queue::fake();
        $this->at('2026-10-10 09:00');
        $campaign = $this->service()->activate($this->intervalCampaign(contents: [Content::factory()->create()]));
        $this->service()->process($campaign->id, PostTrigger::Schedule);
        $log = PostingLog::query()->sole();

        // Simulate worker A having won the flag a moment ago and still talking to Telegram.
        PostingLog::query()->whereKey($log->id)->update(['api_called_at' => now()]);

        $this->assertSame('duplicate', app(PostingService::class)->deliver($log->id));
        $this->telegram->assertSentCount(0);
    }

    #[Test]
    public function flood_control_waits_for_retry_after_without_calling_telegram_during_the_cooldown(): void
    {
        $this->at('2026-10-10 09:00');
        $campaign = $this->intervalCampaign(contents: [Content::factory()->create()]);
        $this->telegram->failNext('sendMessage', 429, 'Too Many Requests: retry after 40', ['retry_after' => 40]);

        $this->service()->activate($campaign);
        $this->tick();

        $item = $campaign->items()->sole();
        $this->assertSame(ItemStatus::Pending, $item->status);
        $this->assertEqualsWithDelta(41, now()->diffInSeconds($item->next_attempt_at), 1);
        $this->assertSame(0, $item->failed_attempts, 'Flood waits do not consume the retry budget');
        $this->assertGreaterThan(now()->getTimestamp(), Cache::get(PostingService::cooldownKey($campaign->bot_id)));

        // During the cooldown nothing is sent.
        $this->at('2026-10-10 09:00:20');
        $this->tick();
        $this->assertCount(1, $this->telegram->sends());

        $this->at('2026-10-10 09:01:30');
        $this->tick();
        $this->assertCount(2, $this->telegram->sends());
        $this->assertSame(ItemStatus::Sent, $item->refresh()->status);
        $this->assertSame(PostTrigger::Retry, PostingLog::query()->latest('id')->first()->trigger);
    }

    #[Test]
    public function temporary_errors_are_retried_then_the_campaign_continues(): void
    {
        $this->at('2026-10-10 09:00');
        $campaign = $this->intervalCampaign(['max_retries' => 2, 'retry_delay_seconds' => 60], [Content::factory()->create(['title' => 'A']), Content::factory()->create(['title' => 'B'])]);
        $this->telegram->failNext('sendMessage', 502, 'Bad Gateway', times: 3);

        $this->service()->activate($campaign);
        $this->tick(); // attempt 1 fails

        // Backoff: 60 s, then 120 s (+ jitter).
        $this->at('2026-10-10 09:02');
        $this->tick(); // attempt 2 fails
        $this->at('2026-10-10 09:06');
        $this->tick(); // attempt 3 fails -> budget exhausted

        $first = $campaign->items()->where('sort_order', 1)->sole();
        $this->assertSame(ItemStatus::Failed, $first->status);
        $this->assertSame(3, $first->attempts);
        $this->assertSame(3, PostingLog::query()->where('campaign_item_id', $first->id)->where('status', PostingStatus::Failed)->count());

        // The campaign keeps going with item B at the next slot.
        $this->at('2026-10-10 09:30');
        $this->tick();
        $this->assertSame(ItemStatus::Sent, $campaign->items()->where('sort_order', 2)->sole()->status);
        $this->assertSame(CampaignStatus::Completed, $campaign->refresh()->status);
    }

    #[Test]
    public function stop_on_failure_marks_the_campaign_failed(): void
    {
        $this->at('2026-10-10 09:00');
        $campaign = $this->intervalCampaign(['failure_behavior' => FailureBehavior::StopOnFailure, 'max_retries' => 0], [Content::factory()->create(), Content::factory()->create()]);
        $this->telegram->failNext('sendMessage', 500, 'Internal Server Error');

        $this->service()->activate($campaign);
        $this->tick();

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Failed, $campaign->status);
        $this->assertNull($campaign->next_run_at);
        $this->assertStringContainsString('item #1', $campaign->last_error);

        $this->at('2026-10-10 10:00');
        $this->tick();
        $this->telegram->assertSentCount(1);
    }

    #[Test]
    public function permanent_errors_are_not_retried_and_flag_the_destination(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->at('2026-10-10 09:00');
        $campaign = $this->intervalCampaign(['failure_behavior' => FailureBehavior::RetryThenContinue], [Content::factory()->create()]);
        $campaign->destination->forceFill(['connection_status' => ConnectionStatus::Connected])->save();
        $this->telegram->failNext('sendMessage', 403, 'Forbidden: bot is not a member of the channel chat');

        $this->service()->activate($campaign);
        $this->tick();

        $this->assertSame(ItemStatus::Failed, $campaign->items()->sole()->status);
        $this->assertSame(ConnectionStatus::Failed, $campaign->destination->refresh()->connection_status);
        $this->assertSame(1, $admin->notifications()->where('data->category', 'permission_failure')->count());
    }

    #[Test]
    public function the_telegram_file_id_is_reused_after_the_first_upload(): void
    {
        $this->at('2026-10-10 09:00');
        $video = Content::factory()->video('https://cdn.example.org/clip.mp4')->create();
        $campaign = $this->intervalCampaign(['repeat_mode' => RepeatMode::Immediate, 'repeat_limit' => 1], [$video]);

        $this->service()->activate($campaign);
        $this->tick();

        $first = $this->telegram->lastSend();
        $this->assertSame('https://cdn.example.org/clip.mp4', $first['params']['video']);
        $cached = ContentBotFile::query()->where('content_id', $video->id)->where('bot_id', $campaign->bot_id)->sole();

        $this->at('2026-10-10 09:30');
        $this->tick();

        $second = $this->telegram->lastSend();
        $this->assertSame($cached->file_id, $second['params']['video'], 'The second post must use the cached file_id instead of the URL');
        $this->assertSame(CampaignStatus::Completed, $campaign->refresh()->status, 'repeat_limit=1 allows exactly one extra cycle');
        $this->assertSame(2, $campaign->cycle);
    }

    #[Test]
    public function an_invalid_cached_file_id_falls_back_to_the_original_url(): void
    {
        $this->at('2026-10-10 09:00');
        $image = Content::factory()->image('https://cdn.example.org/pic.jpg')->create();
        $campaign = $this->intervalCampaign([], [$image]);
        ContentBotFile::create(['content_id' => $image->id, 'bot_id' => $campaign->bot_id, 'file_id' => 'stale-id']);
        $this->telegram->failNext('sendPhoto', 400, 'Bad Request: wrong file identifier/HTTP URL specified');

        $this->service()->activate($campaign);
        $this->tick();

        $sends = $this->telegram->sends();
        $this->assertSame('stale-id', $sends[0]['params']['photo']);
        $this->assertSame('https://cdn.example.org/pic.jpg', $sends[1]['params']['photo']);
        $this->assertSame(ItemStatus::Sent, $campaign->items()->sole()->status);
        $this->assertNotSame('stale-id', ContentBotFile::query()->where('content_id', $image->id)->value('file_id'));
    }

    #[Test]
    public function recovery_never_resends_an_attempt_that_may_have_reached_telegram(): void
    {
        Queue::fake();
        $this->at('2026-10-10 09:00');
        $campaign = $this->service()->activate($this->intervalCampaign(contents: [Content::factory()->create(), Content::factory()->create()]));
        $this->service()->process($campaign->id, PostTrigger::Schedule);

        // Crash right after the request was sent to Telegram.
        $log = PostingLog::query()->sole();
        $log->forceFill(['api_called_at' => now()])->save();

        $this->at('2026-10-10 09:20');
        app(RecoveryService::class)->recover();

        $this->telegram->assertSentCount(0);
        $item = $campaign->items()->where('sort_order', 1)->sole();
        $this->assertSame(ItemStatus::Failed, $item->status);
        $this->assertStringContainsString('not re-sent', $item->error_message);
        $this->assertNull($item->next_attempt_at);
    }

    #[Test]
    public function recovery_requeues_an_attempt_that_never_reached_telegram(): void
    {
        Queue::fake();
        $this->at('2026-10-10 09:00');
        $campaign = $this->service()->activate($this->intervalCampaign(contents: [Content::factory()->create()]));
        $this->service()->process($campaign->id, PostTrigger::Schedule);
        Queue::assertPushed(SendTelegramMessageJob::class, 1);

        // The queued job was lost in a restart (api_called_at is still null).
        $this->at('2026-10-10 09:20');
        $stats = app(RecoveryService::class)->recover();

        $this->assertSame(1, $stats['requeued']);
        Queue::assertPushed(SendTelegramMessageJob::class, 2);
    }

    #[Test]
    public function restart_starts_again_from_item_one_and_keeps_history(): void
    {
        $this->at('2026-10-10 09:00');
        $campaign = $this->service()->activate($this->intervalCampaign());
        $this->tick();
        $this->at('2026-10-10 09:30');
        $this->tick();

        $this->at('2026-10-10 09:45');
        $campaign = $this->service()->restart($campaign);
        $this->tick();

        $this->assertSame(3, PostingLog::query()->count(), 'Historical logs are preserved');
        $this->assertSame(2, $campaign->cycle);
        $this->assertSame('sendVideo', $this->telegram->lastSend()['method']);
        $this->assertSame(ItemStatus::Sent, $campaign->items()->where('sort_order', 1)->sole()->status);
        $this->assertSame(4, $campaign->items()->where('status', ItemStatus::Pending)->count());
    }

    #[Test]
    public function skipped_items_are_passed_over(): void
    {
        $this->at('2026-10-10 09:00');
        $campaign = $this->service()->activate($this->intervalCampaign());
        $this->service()->skipItem($campaign->items()->where('sort_order', 1)->sole());
        $this->tick();

        $this->assertSame('sendVideo', $this->telegram->lastSend()['method']);
        $this->assertSame(ItemStatus::Sent, $campaign->items()->where('sort_order', 2)->sole()->status);
        $this->assertDatabaseHas('posting_logs', ['status' => 'skipped', 'campaign_item_id' => $campaign->items()->where('sort_order', 1)->value('id')]);
    }

    #[Test]
    public function test_mode_posts_to_the_test_chat_instead_of_the_real_destination(): void
    {
        $this->at('2026-10-10 09:00');
        $campaign = $this->intervalCampaign(contents: [Content::factory()->create()]);
        $testChat = TelegramDestination::factory()->testChat()->create(['bot_id' => $campaign->bot_id, 'chat_id' => '-1009999999999']);
        $campaign->update(['test_mode' => true, 'test_destination_id' => $testChat->id]);

        $this->service()->activate($campaign);
        $this->tick();

        $this->assertSame('-1009999999999', (string) $this->telegram->lastSend()['params']['chat_id']);
        $this->assertTrue(PostingLog::query()->sole()->is_test);
    }

    #[Test]
    public function random_mode_posts_every_item_exactly_once_per_cycle(): void
    {
        $this->at('2026-10-10 09:00');
        $campaign = $this->service()->activate($this->intervalCampaign(['posting_order' => PostingOrder::Random]));

        foreach (['09:00', '09:30', '10:00', '10:30', '11:00'] as $time) {
            $this->at("2026-10-10 {$time}");
            $this->tick();
        }

        $this->assertSame(5, PostingLog::query()->distinct()->count('campaign_item_id'));
        $this->assertSame(CampaignStatus::Completed, $campaign->refresh()->status);
    }

    #[Test]
    public function weekly_trigger_posts_on_selected_days_only(): void
    {
        $this->at('2026-10-10 08:00'); // Saturday
        $campaign = Campaign::factory()->withItems(3)->create(['trigger_type' => TriggerType::Weekly, 'interval_seconds' => null]);
        Schedule::create(['campaign_id' => $campaign->id, 'schedule_type' => 'weekly', 'time_of_day' => '19:00:00', 'days' => [1, 3, 5], 'timezone' => 'Asia/Karachi']);

        $campaign = $this->service()->activate($campaign);
        $this->assertSame('Mon 19:00', $campaign->next_run_at->setTimezone('Asia/Karachi')->format('D H:i'));

        $this->at('2026-10-11 19:00'); // Sunday
        $this->tick();
        $this->telegram->assertSentCount(0);

        $this->at('2026-10-12 19:00'); // Monday
        $this->tick();
        $this->telegram->assertSentCount(1);
        $this->assertSame('Wed 19:00', $campaign->refresh()->next_run_at->setTimezone('Asia/Karachi')->format('D H:i'));
    }

    #[Test]
    public function repeat_daily_starts_the_next_cycle_the_following_day(): void
    {
        $this->at('2026-10-10 09:00');
        $campaign = $this->service()->activate($this->intervalCampaign(['repeat_mode' => RepeatMode::Daily], [Content::factory()->create(), Content::factory()->create()]));
        $this->tick();
        $this->at('2026-10-10 09:30');
        $this->tick();

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Running, $campaign->status);
        $this->assertSame(2, $campaign->cycle);
        $this->assertSame('2026-10-11 09:00', $campaign->next_run_at->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));
        $this->assertSame(2, $campaign->items()->where('status', ItemStatus::Pending)->count());
    }

    #[Test]
    public function scheduled_trigger_uses_each_items_exact_time(): void
    {
        $this->at('2026-10-10 08:00');
        $campaign = Campaign::factory()->withItems(2)->create(['trigger_type' => TriggerType::Scheduled, 'interval_seconds' => null]);
        $items = $campaign->items()->get();
        $items[0]->update(['scheduled_at' => CarbonImmutable::parse('2026-10-10 10:15', 'Asia/Karachi')->utc()]);
        $items[1]->update(['scheduled_at' => CarbonImmutable::parse('2026-10-12 18:40', 'Asia/Karachi')->utc()]);

        $campaign = $this->service()->activate($campaign);
        $this->assertSame('2026-10-10 10:15', $campaign->next_run_at->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));

        $this->at('2026-10-10 10:15');
        $this->tick();
        $this->assertSame('2026-10-12 18:40', $campaign->refresh()->next_run_at->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));
    }

    #[Test]
    public function multiple_bots_and_campaigns_run_independently(): void
    {
        $this->at('2026-10-10 09:00');
        $a = $this->service()->activate(Campaign::factory()->withItems(2)->create(['interval_seconds' => 1800]));
        $b = $this->service()->activate(Campaign::factory()->withItems(2)->create(['interval_seconds' => 3600]));

        $this->tick();
        $this->at('2026-10-10 09:30');
        $this->tick();

        $this->assertSame(2, PostingLog::query()->where('campaign_id', $a->id)->count());
        $this->assertSame(1, PostingLog::query()->where('campaign_id', $b->id)->count());

        $tokens = collect($this->telegram->sends())->pluck('token')->unique();
        $this->assertCount(2, $tokens, 'Each campaign posts with its own bot token');
    }

    #[Test]
    public function inactive_content_is_skipped_at_send_time(): void
    {
        $this->at('2026-10-10 09:00');
        $campaign = $this->service()->activate($this->intervalCampaign(contents: [Content::factory()->inactive()->create(), Content::factory()->create()]));
        $this->tick();

        $this->telegram->assertSentCount(0);
        $this->assertSame(ItemStatus::Skipped, $campaign->items()->where('sort_order', 1)->sole()->status);
    }

    #[Test]
    public function stored_responses_never_contain_the_bot_token(): void
    {
        $this->at('2026-10-10 09:00');
        $campaign = $this->service()->activate($this->intervalCampaign(contents: [Content::factory()->create()]));
        $this->telegram->failNext('sendMessage', 400, 'Bad Request: chat not found');
        $this->tick();

        $token = $campaign->bot->token;
        $secret = substr($token, strpos($token, ':') + 1);

        PostingLog::query()->get()->each(function (PostingLog $log) use ($secret) {
            $this->assertStringNotContainsString($secret, json_encode($log->toArray()));
        });
        $this->assertStringNotContainsString($secret, json_encode($campaign->bot->toArray()));
    }

    #[Test]
    public function the_job_middleware_releases_jobs_during_a_bot_cooldown(): void
    {
        $campaign = Campaign::factory()->withItems(1)->create();
        app(PostingService::class)->startCooldown($campaign->bot_id, 30);

        $job = (new SendTelegramMessageJob(123, $campaign->bot_id, '-100'))->withFakeQueueInteractions();

        $ran = false;
        (new TelegramRateLimit)->handle($job, function () use (&$ran) {
            $ran = true;
        });

        $this->assertFalse($ran);
        $job->assertReleased();
        $this->telegram->assertSentCount(0);
    }
}
