<?php

namespace Tests\Feature;

use App\Enums\PostingStatus;
use App\Enums\PostTrigger;
use App\Livewire\AutoQueues\Index as AutoQueuePage;
use App\Models\AutoQueue;
use App\Models\Content;
use App\Models\Plan;
use App\Models\PostingLog;
use App\Models\Tag;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Models\User;
use App\Services\AutoQueueService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeTelegram;
use Tests\TestCase;

class AutoQueueTest extends TestCase
{
    use RefreshDatabase;

    private FakeTelegram $telegram;

    private const TZ = 'Asia/Karachi';

    protected function setUp(): void
    {
        parent::setUp();
        $this->telegram = FakeTelegram::install();
        // Monday 19 Oct 2026, 08:00 in Karachi.
        $this->travelTo(CarbonImmutable::parse('2026-10-19 08:00', self::TZ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function queue(?User $owner = null, array $data = []): AutoQueue
    {
        $bot = TelegramBot::factory()->create(['user_id' => $owner?->id]);
        $channel = TelegramDestination::factory()->create(['bot_id' => $bot->id, 'name' => 'Daily Channel']);

        return app(AutoQueueService::class)->save($data + [
            'name' => 'Daily', 'bot_id' => $bot->id, 'destination_id' => $channel->id, 'timezone' => self::TZ,
            'times' => ['14:00', '09:00'], 'days' => [1, 2, 3, 4, 5, 6, 7], 'order' => 'oldest',
        ], null, $owner);
    }

    private function content(?User $owner, string $title, int $minutesAgo): Content
    {
        return Content::factory()->create(['user_id' => $owner?->id, 'title' => $title, 'text' => "Post: {$title}", 'created_at' => now()->subMinutes($minutesAgo)]);
    }

    private function at(string $localTime): void
    {
        $this->travelTo(CarbonImmutable::parse($localTime, self::TZ));
    }

    /**
     * @return list<string>
     */
    private function sentTexts(): array
    {
        return array_map(fn ($call) => $call['params']['text'], $this->telegram->sends());
    }

    #[Test]
    public function posting_times_follow_the_timezone_and_days(): void
    {
        $queue = $this->queue();
        $this->assertTrue($queue->next_run_at->equalTo(CarbonImmutable::parse('2026-10-19 09:00', self::TZ)));
        $this->assertSame(['09:00', '14:00'], $queue->times);

        $service = app(AutoQueueService::class);
        $this->assertTrue($service->nextSlot($queue, CarbonImmutable::parse('2026-10-19 14:00', self::TZ))
            ->equalTo(CarbonImmutable::parse('2026-10-20 09:00', self::TZ)));

        $queue->days = [3]; // Wednesdays only
        $this->assertTrue($service->nextSlot($queue, CarbonImmutable::now())->equalTo(CarbonImmutable::parse('2026-10-21 09:00', self::TZ)));
        $this->assertCount(5, $service->upcomingSlots($queue, 5));
    }

    #[Test]
    public function interval_posting_keeps_its_rhythm_inside_the_posting_hours(): void
    {
        $service = app(AutoQueueService::class);
        $queue = $this->queue(null, ['mode' => 'interval', 'interval_seconds' => 1800]);
        $local = fn (string $time) => CarbonImmutable::parse($time, self::TZ);

        // Monday 08:00 → every 30 minutes from now.
        $this->assertTrue($queue->next_run_at->equalTo($local('2026-10-19 08:30')));
        // A post that went out a few seconds late does not shift the rhythm.
        $this->assertTrue($service->nextSlot($queue, $local('2026-10-19 08:30:07'), $local('2026-10-19 08:30'))->equalTo($local('2026-10-19 09:00')));
        // After downtime the next tick is used, no backlog.
        $this->assertTrue($service->nextSlot($queue, $local('2026-10-19 09:47'), $local('2026-10-19 09:00'))->equalTo($local('2026-10-19 10:00')));

        // Only between 09:00 and 22:00.
        $queue->active_from = '09:00';
        $queue->active_until = '22:00';
        $this->assertTrue($service->nextSlot($queue, $local('2026-10-19 08:00'))->equalTo($local('2026-10-19 09:00')));
        $this->assertTrue($service->nextSlot($queue, $local('2026-10-19 21:50'), $local('2026-10-19 21:50'))->equalTo($local('2026-10-20 09:00')));

        // A night window runs past midnight.
        $queue->active_from = '22:00';
        $queue->active_until = '02:00';
        $this->assertTrue($service->nextSlot($queue, $local('2026-10-20 01:00'), $local('2026-10-20 01:00'))->equalTo($local('2026-10-20 01:30')));
        $this->assertTrue($service->nextSlot($queue, $local('2026-10-20 01:45'), $local('2026-10-20 01:45'))->equalTo($local('2026-10-20 22:00')));
    }

    #[Test]
    public function intervals_respect_telegram_and_the_plan(): void
    {
        try {
            $this->queue(null, ['mode' => 'interval', 'interval_seconds' => 5]);
            $this->fail('5 seconds is faster than Telegram allows.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('every 10 seconds', $e->getMessage());
        }

        $this->assertSame(10, $this->queue(null, ['mode' => 'interval', 'interval_seconds' => 10])->interval_seconds);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('every 30 minute(s)');
        $this->queue(User::factory()->customer()->create(), ['mode' => 'interval', 'interval_seconds' => 60]);
    }

    #[Test]
    public function the_quick_buttons_fill_the_schedule(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $bot = TelegramBot::factory()->create();
        $channel = TelegramDestination::factory()->create(['bot_id' => $bot->id]);

        $page = Livewire::test(AutoQueuePage::class)
            ->call('create')
            ->set('form.bot_id', (string) $bot->id)
            ->set('form.destination_id', (string) $channel->id)
            ->call('preset', '1m')
            ->assertSet('form.mode', 'interval')
            ->assertSet('form.interval_value', '1')
            ->assertSet('form.interval_unit', 'minutes')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Every minute');

        $queue = AutoQueue::query()->sole();
        $this->assertSame(60, $queue->interval_seconds);
        $this->assertSame([], $queue->times);

        $page->call('edit', $queue->id)
            ->assertSet('form.interval_unit', 'minutes')
            ->set('form.interval_value', '5')
            ->set('form.interval_unit', 'seconds')
            ->call('save')
            ->assertHasErrors(['form.interval_value'])
            ->call('preset', 'daily')
            ->assertSet('form.mode', 'times')
            ->assertSet('form.times', ['09:00'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['09:00'], $queue->fresh()->times);
        $this->assertNull($queue->fresh()->interval_seconds);
    }

    #[Test]
    public function the_library_is_posted_in_order_and_starts_over(): void
    {
        $this->content(null, 'First', 30);
        $this->content(null, 'Second', 20);
        $queue = $this->queue();
        $service = app(AutoQueueService::class);

        $this->at('2026-10-19 09:00:05');
        $this->assertSame(1, $service->dispatchDue());
        $this->assertSame(0, $service->dispatchDue(), 'A time is used once.');

        // New content joins the line by itself.
        $this->content(null, 'Third', 0);

        foreach (['2026-10-19 14:00:03', '2026-10-20 09:00:02', '2026-10-20 14:00:01'] as $time) {
            $this->at($time);
            $service->dispatchDue();
        }

        $this->assertSame(['Post: First', 'Post: Second', 'Post: Third', 'Post: First'], $this->sentTexts());
        $queue->refresh();
        $this->assertSame(2, $queue->round);
        $this->assertTrue($queue->next_run_at->equalTo(CarbonImmutable::parse('2026-10-21 09:00', self::TZ)));

        $log = PostingLog::query()->latest('id')->first();
        $this->assertSame(PostTrigger::AutoQueue, $log->trigger);
        $this->assertSame(PostingStatus::Sent, $log->status);
        $this->assertSame($queue->destination->chat_id, (string) $this->telegram->lastSend()['params']['chat_id']);
    }

    #[Test]
    public function a_missed_time_is_skipped_instead_of_posted_late(): void
    {
        $this->content(null, 'First', 10);
        $queue = $this->queue();

        $this->at('2026-10-19 10:30');
        app(AutoQueueService::class)->dispatchDue();

        $this->telegram->assertSentCount(0);
        $queue->refresh();
        $this->assertStringContainsString('09:00 post was skipped', $queue->last_error);
        $this->assertTrue($queue->next_run_at->equalTo(CarbonImmutable::parse('2026-10-19 14:00', self::TZ)));
    }

    #[Test]
    public function only_active_content_with_the_chosen_tag_is_posted(): void
    {
        $tag = Tag::query()->create(['name' => 'Offers']);
        $this->content(null, 'Untagged', 30);
        $this->content(null, 'Inactive offer', 25)->forceFill(['status' => 'inactive'])->save();
        $offer = $this->content(null, 'Offer', 20);
        $offer->tags()->attach($tag);
        $queue = $this->queue(null, ['tag_id' => $tag->id]);

        $this->at('2026-10-19 09:00:01');
        app(AutoQueueService::class)->dispatchDue();

        $this->assertSame(['Post: Offer'], $this->sentTexts());
        $this->assertSame(1, app(AutoQueueService::class)->eligible($queue)->count());
    }

    #[Test]
    public function plan_limits_and_an_empty_library_skip_the_time_with_a_reason(): void
    {
        $customer = User::factory()->customer()->create();
        $queue = $this->queue($customer);

        $this->at('2026-10-19 09:00:01');
        app(AutoQueueService::class)->dispatchDue();
        $this->assertStringContainsString('no active content', $queue->fresh()->last_error);

        $this->content($customer, 'Mine', 5);
        Plan::fallback()->update(['posts_per_day' => 0]);
        $this->at('2026-10-19 14:00:01');
        app(AutoQueueService::class)->dispatchDue();
        $this->assertStringContainsString('posting limit', $queue->fresh()->last_error);
        $this->telegram->assertSentCount(0);

        $this->expectException(ValidationException::class);
        app(AutoQueueService::class)->runSlot($queue->id, manual: true);
    }

    #[Test]
    public function flood_control_waits_and_posts_once(): void
    {
        $this->content(null, 'First', 10);
        $this->queue();
        $this->telegram->failNext('sendMessage', 429, 'Too Many Requests: retry after 5', ['retry_after' => 5]);

        $this->at('2026-10-19 09:00:01');
        app(AutoQueueService::class)->dispatchDue();

        $log = PostingLog::query()->sole();
        $this->assertSame(PostingStatus::Processing, $log->status);
        $this->assertNull($log->api_called_at);

        $this->assertSame('sent', app(AutoQueueService::class)->deliver($log->id));
        $this->assertSame('stale', app(AutoQueueService::class)->deliver($log->id));
        $this->assertSame(['Post: First', 'Post: First'], $this->sentTexts()); // the flooded attempt + the real one
    }

    #[Test]
    public function the_page_creates_pauses_and_posts_now(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $bot = TelegramBot::factory()->create(['name' => 'Poster']);
        $channel = TelegramDestination::factory()->create(['bot_id' => $bot->id, 'name' => 'Main']);
        $this->content($admin, 'Hello world', 5);

        $page = Livewire::test(AutoQueuePage::class)
            ->assertSee('No auto queue yet')
            ->call('create')
            ->set('form.bot_id', (string) $bot->id)
            ->set('form.destination_id', (string) $channel->id)
            ->set('form.times', ['10:30'])
            ->set('form.days', ['1', '3', '5'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('10:30')
            ->assertSee('Mon, Wed, Fri')
            ->assertSee('Hello world');

        $queue = AutoQueue::query()->sole();
        $this->assertSame([1, 3, 5], $queue->days);
        $this->assertTrue($queue->next_run_at->equalTo(CarbonImmutable::parse('2026-10-19 10:30', self::TZ)));

        $page->call('postNow', $queue->id)->assertHasNoErrors();
        $this->assertSame(['Post: Hello world'], $this->sentTexts());

        $page->call('toggle', $queue->id);
        $this->assertSame(AutoQueue::PAUSED, $queue->fresh()->status);
        $this->assertNull($queue->fresh()->next_run_at);
    }

    #[Test]
    public function a_queue_only_posts_its_own_accounts_content(): void
    {
        $alice = User::factory()->customer()->create();
        $bob = User::factory()->customer()->create();
        $this->content($bob, 'Bob post', 30);
        $this->content($alice, 'Alice post', 10);
        $this->queue($alice);

        $this->at('2026-10-19 09:00:01');
        app(AutoQueueService::class)->dispatchDue();

        $this->assertSame(['Post: Alice post'], $this->sentTexts());
        $this->actingAs($bob)->get('/auto-queue')->assertOk()->assertDontSee('Daily Channel');
    }
}
