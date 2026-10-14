<?php

namespace Tests\Feature;

use App\Enums\PostingStatus;
use App\Enums\PostTrigger;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Livewire\Welcome\Index as WelcomePage;
use App\Models\BotSubscriber;
use App\Models\Plan;
use App\Models\PostingLog;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Models\User;
use App\Services\WelcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeTelegram;
use Tests\TestCase;

class WelcomeTest extends TestCase
{
    use RefreshDatabase;

    private FakeTelegram $telegram;

    private int $updateId = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->telegram = FakeTelegram::install();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function channel(array $attributes = [], ?User $owner = null): TelegramDestination
    {
        $bot = TelegramBot::factory()->create(['user_id' => $owner?->id]);
        $bot->forceFill(['webhook_secret' => 'test-secret-123', 'updates_connected_at' => now()])->save();

        return TelegramDestination::factory()->create($attributes + [
            'bot_id' => $bot->id,
            'name' => 'Deals Channel',
            'welcome_enabled' => true,
            'welcome_message' => 'Hi {first_name}, welcome to {chat_title}!',
            'welcome_button_text' => 'Open shop',
            'welcome_button_url' => 'https://shop.example.com',
        ]);
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function deliver(TelegramBot $bot, array $update, ?string $secret = null): TestResponse
    {
        return $this->postJson("/api/telegram/webhook/{$bot->id}", ['update_id' => ++$this->updateId] + $update, [
            'X-Telegram-Bot-Api-Secret-Token' => $secret ?? $bot->webhook_secret,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function joinRequest(TelegramDestination $channel, int $userId = 555, string $firstName = 'Ali'): array
    {
        return ['chat_join_request' => [
            'chat' => ['id' => (int) $channel->chat_id, 'type' => 'channel', 'title' => 'Deals'],
            'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => $firstName, 'username' => 'ali_k'],
            'user_chat_id' => $userId,
            'date' => time(),
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function directJoin(TelegramDestination $channel, int $userId = 777, array $extra = []): array
    {
        return ['chat_member' => $extra + [
            'chat' => ['id' => (int) $channel->chat_id, 'type' => 'channel'],
            'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Sara'],
            'date' => time(),
            'old_chat_member' => ['status' => 'left', 'user' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Sara']],
            'new_chat_member' => ['status' => 'member', 'user' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Sara']],
        ]];
    }

    /**
     * @return list<array{method: string, token: string, params: array<string, mixed>}>
     */
    private function calls(string $method): array
    {
        return array_values(array_filter($this->telegram->calls, fn ($call) => $call['method'] === $method));
    }

    #[Test]
    public function connecting_a_bot_registers_a_secret_https_webhook(): void
    {
        $bot = TelegramBot::factory()->create();

        try {
            app(WelcomeService::class)->connect($bot);
            $this->fail('A plain http:// address must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('https://', $e->getMessage());
        }

        URL::forceRootUrl('https://bot.example.com');
        URL::forceScheme('https');
        app(WelcomeService::class)->connect($bot);

        $call = $this->calls('setWebhook')[0];
        $bot->refresh();
        $this->assertSame("https://bot.example.com/api/telegram/webhook/{$bot->id}", $call['params']['url']);
        $this->assertSame($bot->webhook_secret, $call['params']['secret_token']);
        $this->assertSame(WelcomeService::ALLOWED_UPDATES, $call['params']['allowed_updates']);
        $this->assertNotNull($bot->updates_connected_at);
        $this->assertArrayNotHasKey('webhook_secret', $bot->toArray());
    }

    #[Test]
    public function the_webhook_needs_the_secret_and_ignores_group_chatter(): void
    {
        Queue::fake();
        $channel = $this->channel();
        $bot = $channel->bot;

        $this->deliver($bot, $this->joinRequest($channel), 'wrong')->assertForbidden();
        $this->deliver($bot, ['message' => ['chat' => ['id' => -100, 'type' => 'supergroup'], 'text' => 'hello all']])->assertOk();
        Queue::assertNothingPushed();

        $this->deliver($bot, $this->joinRequest($channel))->assertOk();
        Queue::assertPushed(ProcessTelegramUpdateJob::class, fn ($job) => $job->botId === $bot->id && isset($job->update['chat_join_request']));
    }

    #[Test]
    public function a_join_request_gets_the_welcome_dm_and_is_approved(): void
    {
        $channel = $this->channel(['auto_approve_requests' => true]);

        $this->deliver($channel->bot, $this->joinRequest($channel))->assertOk();

        $dm = $this->calls('sendMessage')[0];
        $this->assertSame(555, $dm['params']['chat_id']);
        $this->assertSame('Hi Ali, welcome to Deals Channel!', $dm['params']['text']);
        $this->assertSame([[['text' => 'Open shop', 'url' => 'https://shop.example.com']]], $dm['params']['reply_markup']['inline_keyboard']);

        $approve = $this->calls('approveChatJoinRequest')[0];
        $this->assertSame($channel->chat_id, (string) $approve['params']['chat_id']);
        $this->assertSame(555, $approve['params']['user_id']);
        $this->assertSame(['sendMessage', 'approveChatJoinRequest'], $this->telegram->methods());

        $log = PostingLog::query()->sole();
        $this->assertSame(PostTrigger::Welcome, $log->trigger);
        $this->assertSame(PostingStatus::Sent, $log->status);
        $this->assertSame($channel->id, $log->destination_id);
    }

    #[Test]
    public function the_same_update_twice_sends_one_dm(): void
    {
        $channel = $this->channel();
        $update = ['update_id' => 42] + $this->joinRequest($channel);

        foreach ([1, 2] as $attempt) {
            $this->postJson("/api/telegram/webhook/{$channel->bot_id}", $update, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret-123'])->assertOk();
        }

        $this->assertCount(1, $this->calls('sendMessage'));
    }

    #[Test]
    public function direct_joins_are_welcomed_only_after_the_person_started_the_bot(): void
    {
        $channel = $this->channel();
        $bot = $channel->bot;

        $this->deliver($bot, $this->directJoin($channel, 777));
        $this->assertCount(0, $this->calls('sendMessage'));

        $this->deliver($bot, ['message' => [
            'chat' => ['id' => 777, 'type' => 'private'], 'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Sara', 'username' => 'sara'], 'text' => '/start',
        ]]);
        $this->assertDatabaseHas('bot_subscribers', ['bot_id' => $bot->id, 'telegram_user_id' => 777, 'username' => 'sara']);

        $this->deliver($bot, $this->directJoin($channel, 777));
        $this->assertCount(1, $this->calls('sendMessage'));
        $this->assertSame('Hi Sara, welcome to Deals Channel!', $this->calls('sendMessage')[0]['params']['text']);

        // Joining after an approved request was already welcomed with the request.
        $this->deliver($bot, $this->directJoin($channel, 777, ['via_join_request' => true]));
        $this->assertCount(1, $this->calls('sendMessage'));
    }

    #[Test]
    public function names_are_escaped_for_the_chosen_formatting(): void
    {
        $channel = $this->channel(['welcome_parse_mode' => 'HTML', 'welcome_message' => '<b>Welcome</b> {first_name}']);

        $this->deliver($channel->bot, $this->joinRequest($channel, 555, '<script>&'));

        $dm = $this->calls('sendMessage')[0];
        $this->assertSame('<b>Welcome</b> &lt;script&gt;&amp;', $dm['params']['text']);
        $this->assertSame('HTML', $dm['params']['parse_mode']);
    }

    #[Test]
    public function blocked_users_are_remembered_and_failures_logged(): void
    {
        $channel = $this->channel();
        BotSubscriber::query()->create(['bot_id' => $channel->bot_id, 'telegram_user_id' => 777, 'first_name' => 'Sara']);
        $this->telegram->failNext('sendMessage', 403, 'Forbidden: bot was blocked by the user');

        $this->deliver($channel->bot, $this->directJoin($channel, 777));

        $this->assertNotNull(BotSubscriber::query()->sole()->blocked_at);
        $log = PostingLog::query()->sole();
        $this->assertSame(PostingStatus::Failed, $log->status);
        $this->assertStringContainsString('does not allow this bot to message the user', $log->error_message);
    }

    #[Test]
    public function a_used_up_plan_limit_skips_the_dm_but_still_approves(): void
    {
        $customer = User::factory()->customer()->create();
        Plan::fallback()->update(['posts_per_day' => 0]);
        $channel = $this->channel(['auto_approve_requests' => true], $customer);

        $this->deliver($channel->bot, $this->joinRequest($channel));

        $this->assertSame(['approveChatJoinRequest'], $this->telegram->methods());
        $this->assertDatabaseCount('posting_logs', 0);
    }

    #[Test]
    public function a_join_request_link_can_be_created(): void
    {
        $channel = $this->channel();
        $this->telegram->respondNext('createChatInviteLink', ['invite_link' => 'https://t.me/+AbCdEf', 'creates_join_request' => true]);

        $link = app(WelcomeService::class)->createJoinLink($channel);

        $this->assertSame('https://t.me/+AbCdEf', $link);
        $this->assertSame('https://t.me/+AbCdEf', $channel->fresh()->join_request_link);
        $this->assertTrue($this->calls('createChatInviteLink')[0]['params']['creates_join_request']);
    }

    #[Test]
    public function the_page_saves_settings_and_connects_the_bot(): void
    {
        URL::forceRootUrl('https://bot.example.com');
        URL::forceScheme('https');
        $this->actingAs(User::factory()->admin()->create());
        $bot = TelegramBot::factory()->create();
        $channel = TelegramDestination::factory()->create(['bot_id' => $bot->id, 'name' => 'News']);

        Livewire::test(WelcomePage::class)
            ->assertSee('News')
            ->call('edit', $channel->id)
            ->assertSet('form.welcome_message', WelcomeService::DEFAULT_MESSAGE)
            ->set('form.welcome_enabled', true)
            ->set('form.welcome_message', 'Hello {first_name}')
            ->set('form.welcome_button_text', 'Site')
            ->call('save')
            ->assertHasErrors(['form.welcome_button_url' => 'required_with'])
            ->set('form.welcome_button_url', 'https://example.com')
            ->set('form.auto_approve_requests', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $channel->refresh();
        $this->assertTrue($channel->welcome_enabled);
        $this->assertTrue($channel->auto_approve_requests);
        $this->assertSame('Hello {first_name}', $channel->welcome_message);
        $this->assertNotNull($bot->fresh()->updates_connected_at);
        $this->assertCount(1, $this->calls('setWebhook'));
    }

    #[Test]
    public function customers_only_see_their_own_chats(): void
    {
        $alice = User::factory()->customer()->create();
        $bob = User::factory()->customer()->create();
        $this->channel(['name' => 'Alice Channel'], $alice);
        $this->channel(['name' => 'Bob Channel'], $bob);

        $this->actingAs($alice)->get('/welcome-messages')->assertOk()->assertSee('Alice Channel')->assertDontSee('Bob Channel');
    }
}
