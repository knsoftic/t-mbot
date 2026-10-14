<?php

namespace Tests\Feature;

use App\Enums\BotStatus;
use App\Enums\PostingStatus;
use App\Enums\PostTrigger;
use App\Livewire\QuickSend\Index as QuickSend;
use App\Models\Plan;
use App\Models\PostingLog;
use App\Models\QuickSendTarget;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Models\User;
use App\Services\PostingService;
use App\Services\QuickSendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeTelegram;
use Tests\TestCase;

class QuickSendTest extends TestCase
{
    use RefreshDatabase;

    private FakeTelegram $telegram;

    protected function setUp(): void
    {
        parent::setUp();
        $this->telegram = FakeTelegram::install();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function target(?User $owner = null, array $attributes = []): QuickSendTarget
    {
        $bot = TelegramBot::factory()->create(['user_id' => $owner?->id]);
        $channel = TelegramDestination::factory()->create(['bot_id' => $bot->id, 'name' => 'News channel']);

        return app(QuickSendService::class)->saveTarget($attributes + [
            'name' => 'News', 'bot_id' => $bot->id, 'destination_id' => $channel->id,
        ], null, $owner);
    }

    #[Test]
    public function a_typed_message_is_sent_to_the_saved_chat_right_away(): void
    {
        $target = $this->target(null, ['parse_mode' => 'HTML', 'disable_notification' => true]);

        $log = app(QuickSendService::class)->send($target, "  <b>Breaking</b>\r\nNews  ");

        $log->refresh();
        $this->assertSame(PostingStatus::Sent, $log->status);
        $this->assertSame(PostTrigger::QuickSend, $log->trigger);
        $this->assertNotNull($log->telegram_message_id);
        $this->assertSame("<b>Breaking</b>\nNews", $log->message);

        $call = $this->telegram->lastSend();
        $this->assertSame('sendMessage', $call['method']);
        $this->assertSame($target->destination->chat_id, (string) $call['params']['chat_id']);
        $this->assertSame("<b>Breaking</b>\nNews", $call['params']['text']);
        $this->assertSame('HTML', $call['params']['parse_mode']);
        $this->assertTrue($call['params']['disable_notification']);
        $this->assertNotNull($target->fresh()->last_used_at);
    }

    #[Test]
    public function the_page_saves_a_target_and_sends_with_one_click(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $bot = TelegramBot::factory()->create(['name' => 'Promo Bot']);
        $group = TelegramDestination::factory()->group()->create(['bot_id' => $bot->id, 'name' => 'Buyers group']);

        $page = Livewire::test(QuickSend::class)
            ->assertSee('No Quick Send targets yet')
            ->call('create')
            ->set('form.bot_id', (string) $bot->id)
            ->set('form.destination_id', (string) $group->id)
            ->assertSet('form.name', 'Buyers group')
            ->call('saveTarget')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $target = QuickSendTarget::query()->sole();
        $this->assertSame($target->id, $page->get('targetId'));

        $page->call('send')->assertHasErrors(['message' => 'required']);

        $page->set('message', 'Sale ends tonight! 🔥')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('message', '')
            ->assertSee('Sent at')
            ->assertSee('Sale ends tonight! 🔥');

        $this->telegram->assertSentCount(1);
        $this->get('/logs')->assertOk()->assertSee('Sale ends tonight!')->assertSee('Quick Send');
    }

    #[Test]
    public function the_chat_must_belong_to_the_chosen_bot(): void
    {
        $bot = TelegramBot::factory()->create();
        $otherChannel = TelegramDestination::factory()->create();

        $this->expectException(ValidationException::class);
        app(QuickSendService::class)->saveTarget(['name' => 'X', 'bot_id' => $bot->id, 'destination_id' => $otherChannel->id], null, null);
    }

    #[Test]
    public function flood_control_waits_and_tries_again_without_duplicating(): void
    {
        $target = $this->target();
        $this->telegram->failNext('sendMessage', 429, 'Too Many Requests: retry after 7', ['retry_after' => 7]);

        $log = app(QuickSendService::class)->send($target, 'Hello');

        // The sync queue cannot release the job: the log waits for the retry.
        $log->refresh();
        $this->assertSame(PostingStatus::Processing, $log->status);
        $this->assertNull($log->api_called_at);
        $this->assertGreaterThan(now()->getTimestamp(), (int) Cache::get(PostingService::cooldownKey($target->bot_id)));

        $this->assertSame('sent', app(QuickSendService::class)->deliver($log->id));
        $this->assertSame('stale', app(QuickSendService::class)->deliver($log->id));
        $this->assertSame(PostingStatus::Sent, $log->fresh()->status);
        $this->telegram->assertSentCount(2);
    }

    #[Test]
    public function telegram_errors_are_shown_and_disabled_bots_are_refused(): void
    {
        $target = $this->target();
        $this->telegram->failNext('sendMessage', 403, 'Forbidden: bot is not a member of the channel chat');

        $log = app(QuickSendService::class)->send($target, 'Hello');

        $this->assertSame(PostingStatus::Failed, $log->fresh()->status);
        $this->assertNotEmpty($log->fresh()->error_message);

        $target->bot->update(['status' => BotStatus::Disabled]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('is disabled');
        app(QuickSendService::class)->send($target->fresh(), 'Hello again');
    }

    #[Test]
    public function quick_send_counts_towards_the_plan_sending_limit(): void
    {
        $customer = User::factory()->customer()->create();
        Plan::fallback()->update(['posts_per_day' => 1]);
        $this->actingAs($customer);
        $target = $this->target($customer);
        $this->assertSame($customer->id, $target->user_id);

        app(QuickSendService::class)->send($target, 'First');
        $this->assertSame(1, PostingLog::query()->where('user_id', $customer->id)->where('status', PostingStatus::Sent)->count());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('posting limit for this day is used up (1/1)');
        app(QuickSendService::class)->send($target, 'Second');
    }

    #[Test]
    public function customers_cannot_see_or_use_other_accounts_targets(): void
    {
        $alice = User::factory()->customer()->create();
        $bob = User::factory()->customer()->create();
        $bobTarget = $this->target($bob, ['name' => 'Bob channel']);

        $this->actingAs($alice);

        Livewire::test(QuickSend::class)
            ->assertDontSee('Bob channel')
            ->call('select', $bobTarget->id)
            ->assertSet('targetId', null)
            ->set('message', 'Hi')
            ->call('send')
            ->assertHasErrors('message');

        $this->telegram->assertSentCount(0);
    }
}
