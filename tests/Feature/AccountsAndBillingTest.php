<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\OrderStatus;
use App\Enums\PostingStatus;
use App\Enums\PostTrigger;
use App\Livewire\Admin\Orders;
use App\Livewire\Billing\Checkout;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\PostingLog;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Models\User;
use App\Services\BillingService;
use App\Services\BotService;
use App\Services\CampaignBuilder;
use App\Services\CampaignService;
use App\Services\ContentService;
use App\Services\DestinationService;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeTelegram;
use Tests\TestCase;

class AccountsAndBillingTest extends TestCase
{
    use RefreshDatabase;

    private function method(): PaymentMethod
    {
        return PaymentMethod::query()->create(['name' => 'USDT (TRC20)', 'address' => 'TXyz123WalletAddress', 'instructions' => 'Send on TRC20 only.', 'is_active' => true]);
    }

    // ------------------------------------------------------------------
    // Registration
    // ------------------------------------------------------------------

    #[Test]
    public function people_can_register_and_start_on_the_free_plan(): void
    {
        $this->seed(PlansSeeder::class);

        $this->post('/register', [
            'name' => 'Ali Khan',
            'email' => 'ali@example.com',
            'phone' => '+92 300 1234567',
            'password' => 'Secret-pass-123',
            'password_confirmation' => 'Secret-pass-123',
            'confirm' => '1',
        ])->assertRedirect('/dashboard');

        $user = User::query()->where('email', 'ali@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->isCustomer());
        $this->assertSame('Free', $user->currentPlan()->name);
        $this->assertSame('+92 300 1234567', $user->whatsapp);
        $this->get('/dashboard')->assertOk()->assertSee('Your plan')->assertSee('Billing & plans');
    }

    #[Test]
    public function registration_needs_the_confirmation_and_can_be_turned_off(): void
    {
        $this->post('/register', ['name' => 'A', 'email' => 'a@example.com', 'phone' => '+92 300 1234567', 'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123'])
            ->assertSessionHasErrors('confirm');

        app(SettingsService::class)->set(['registration.enabled' => false]);

        $this->get('/register')->assertNotFound();
        $this->get('/login')->assertOk()->assertDontSee('Create a free account');
    }

    // ------------------------------------------------------------------
    // Data isolation
    // ------------------------------------------------------------------

    #[Test]
    public function customers_only_see_their_own_data_and_staff_see_everything(): void
    {
        $alice = User::factory()->customer()->create();
        $bob = User::factory()->customer()->create();
        $aliceBot = TelegramBot::factory()->create(['user_id' => $alice->id, 'name' => 'Alice Bot']);
        $bobBot = TelegramBot::factory()->create(['user_id' => $bob->id, 'name' => 'Bob Bot']);
        $bobCampaign = Campaign::factory()->create(['bot_id' => $bobBot->id, 'name' => 'Bob Campaign']);
        $bobContent = Content::factory()->create(['user_id' => $bob->id, 'title' => 'Bob Secret Post']);

        $this->assertSame($bob->id, $bobCampaign->user_id);
        $this->assertSame($bob->id, $bobCampaign->destination->user_id);

        $this->actingAs($alice);
        $this->get('/bots')->assertOk()->assertSee('Alice Bot')->assertDontSee('Bob Bot');
        $this->get('/contents')->assertOk()->assertDontSee('Bob Secret Post');
        $this->get("/campaigns/{$bobCampaign->id}")->assertNotFound();
        $this->get("/contents/{$bobContent->id}/edit")->assertNotFound();
        $this->get('/users')->assertForbidden();
        $this->get('/admin/purchase-requests')->assertForbidden();
        $this->assertSame([$aliceBot->id], TelegramBot::query()->pluck('id')->all());

        $this->actingAs(User::factory()->admin()->create());
        $this->get('/bots')->assertOk()->assertSee('Alice Bot')->assertSee('Bob Bot');
    }

    #[Test]
    public function a_customer_cannot_use_another_accounts_bot_or_content_in_a_campaign(): void
    {
        $alice = User::factory()->customer('plan-2')->create();
        $bob = User::factory()->customer()->create();
        $aliceBot = TelegramBot::factory()->create(['user_id' => $alice->id]);
        $aliceChannel = TelegramDestination::factory()->create(['bot_id' => $aliceBot->id]);
        $bobBot = TelegramBot::factory()->create(['user_id' => $bob->id]);
        $bobContent = Content::factory()->create(['user_id' => $bob->id]);
        $this->actingAs($alice);

        $payload = [
            'name' => 'Mine', 'bot_id' => $bobBot->id, 'destination_id' => $aliceChannel->id, 'posting_order' => 'sequential',
            'trigger_type' => 'interval', 'timezone' => 'UTC', 'interval_seconds' => 3600, 'repeat_mode' => 'none',
            'failure_behavior' => 'retry_then_continue', 'max_retries' => 3, 'retry_delay_seconds' => 60,
            'items' => [['content_id' => $bobContent->id]],
        ];

        $errors = validator($payload, CampaignBuilder::rules())->errors();
        $this->assertTrue($errors->has('bot_id'));
        $this->assertTrue($errors->has('items.0.content_id'));
    }

    // ------------------------------------------------------------------
    // Plan limits
    // ------------------------------------------------------------------

    #[Test]
    public function the_free_plan_limits_bots_channels_and_the_posting_interval(): void
    {
        FakeTelegram::install();
        $user = User::factory()->customer()->create();
        $this->actingAs($user);

        $bot = app(BotService::class)->create(['name' => 'One', 'token' => '123456:'.str_repeat('A', 35), 'status' => 'active'], $user);
        $this->assertSame($user->id, $bot->user_id);

        try {
            app(BotService::class)->create(['name' => 'Two', 'token' => '654321:'.str_repeat('B', 35), 'status' => 'active'], $user);
            $this->fail('A second bot should be refused on the Free plan.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('allows 1 bot and you already have 1', $e->getMessage());
        }

        $destinations = app(DestinationService::class);
        $channel = $destinations->create(['bot_id' => $bot->id, 'name' => 'C1', 'chat_id' => '-1001111111111', 'type' => 'channel', 'status' => 'active'], $user);
        $this->assertSame($user->id, $channel->user_id);

        $this->expectException(ValidationException::class);
        $destinations->create(['bot_id' => $bot->id, 'name' => 'C2', 'chat_id' => '-1002222222222', 'type' => 'channel', 'status' => 'active'], $user);
    }

    #[Test]
    public function plans_set_the_shortest_interval_and_upload_size(): void
    {
        $user = User::factory()->customer()->create(); // Free: 30 minutes, 10 MB
        $this->actingAs($user);
        $bot = TelegramBot::factory()->create(['user_id' => $user->id]);
        $channel = TelegramDestination::factory()->create(['bot_id' => $bot->id]);

        try {
            app(CampaignBuilder::class)->save([
                'name' => 'Fast', 'bot_id' => $bot->id, 'destination_id' => $channel->id, 'posting_order' => 'sequential',
                'trigger_type' => 'interval', 'timezone' => 'UTC', 'interval_seconds' => 300, 'repeat_mode' => 'none',
                'failure_behavior' => 'retry_then_continue', 'max_retries' => 3, 'retry_delay_seconds' => 60,
            ], null, $user);
            $this->fail('A 5-minute interval should be refused on the Free plan.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('interval_seconds', $e->errors());
        }

        Storage::fake('local');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('up to 10 MB');

        app(ContentService::class)->create(
            ['title' => 'Big', 'type' => 'video', 'source' => 'upload', 'status' => 'active'],
            UploadedFile::fake()->create('big.mp4', 12 * 1024, 'video/mp4'),
            $user,
        );
    }

    // ------------------------------------------------------------------
    // Sending limits
    // ------------------------------------------------------------------

    #[Test]
    public function a_used_up_daily_limit_postpones_the_post_until_the_next_day(): void
    {
        $user = User::factory()->customer()->create(['timezone' => 'Asia/Karachi']);
        Plan::fallback()->update(['posts_per_day' => 2]);
        $campaign = Campaign::factory()->withItems(2)->create([
            'bot_id' => TelegramBot::factory()->create(['user_id' => $user->id])->id,
            'status' => CampaignStatus::Running,
            'next_run_at' => now()->subMinute(),
        ]);

        foreach (range(1, 2) as $i) {
            PostingLog::query()->create(['user_id' => $user->id, 'status' => PostingStatus::Sent, 'trigger' => PostTrigger::Schedule, 'sent_at' => now()]);
        }

        $outcome = app(CampaignService::class)->process($campaign->id, PostTrigger::Schedule);

        $tomorrow = CarbonImmutable::now('Asia/Karachi')->startOfDay()->addDay()->utc();
        $this->assertSame('limit_reached', $outcome);
        $this->assertTrue($campaign->fresh()->next_run_at->equalTo($tomorrow));
        $this->assertSame(0, $campaign->campaignItems()->where('status', 'processing')->count());
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $user->id]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('daily posting limit is used up (2/2)');
        app(CampaignService::class)->runNow($campaign->fresh());
    }

    #[Test]
    public function staff_campaigns_have_no_sending_limit(): void
    {
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::factory()->withItems(1)->create([
            'bot_id' => TelegramBot::factory()->create(['user_id' => $admin->id])->id,
            'status' => CampaignStatus::Running,
            'next_run_at' => now()->subMinute(),
        ]);

        FakeTelegram::install();
        $this->assertSame('dispatched', app(CampaignService::class)->process($campaign->id, PostTrigger::Schedule));
    }

    // ------------------------------------------------------------------
    // Purchase → approve / reject
    // ------------------------------------------------------------------

    #[Test]
    public function a_customer_buys_a_plan_and_staff_approve_it(): void
    {
        $method = $this->method();
        $customer = User::factory()->customer()->create(['whatsapp' => '+92 300 1112222']);
        $admin = User::factory()->superAdmin()->create();
        $plan = Plan::query()->where('slug', 'plan-1')->firstOrFail();

        $this->actingAs($customer);
        $this->get("/billing/checkout/{$plan->id}")->assertOk()->assertSee('Tamam maloomat sahi aur valid dein');

        Livewire::test(Checkout::class, ['plan' => $plan])
            ->call('selectMethod', $method->id)
            ->assertSee('TXyz123WalletAddress')
            ->set('transaction_reference', 'TX-998877')
            ->call('submit')
            ->assertHasErrors(['confirm'])
            ->set('confirm', true)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('billing.index'));

        $order = PlanOrder::query()->sole();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame('5.00', $order->amount_usd);
        $this->assertSame('TXyz123WalletAddress', $order->payment_address);
        $this->assertSame('+92 300 1112222', $order->contact_whatsapp);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $customer->id]);

        // Only one request at a time.
        Livewire::test(Checkout::class, ['plan' => $plan])
            ->call('selectMethod', $method->id)->set('transaction_reference', 'TX-2')->set('confirm', true)
            ->call('submit')
            ->assertHasErrors('plan');

        $this->actingAs($admin);
        Livewire::test(Orders::class)
            ->assertSee($customer->email)
            ->call('open', $order->id)
            ->assertSee('TX-998877')
            ->assertSee('https://wa.me/923001112222', false)
            ->call('approve')
            ->assertHasNoErrors();

        $customer->refresh();
        $this->assertSame($plan->id, $customer->plan_id);
        $this->assertSame('Plan 1', $customer->currentPlan()->name);
        $this->assertEqualsWithDelta(now()->addMonth()->getTimestamp(), $customer->plan_expires_at->getTimestamp(), 5);
        $this->assertSame(OrderStatus::Approved, $order->fresh()->status);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $customer->id]);
    }

    #[Test]
    public function renewing_the_same_plan_extends_it_and_rejections_carry_a_reason(): void
    {
        $method = $this->method();
        $customer = User::factory()->customer('plan-1')->create();
        $admin = User::factory()->superAdmin()->create();
        $expiry = $customer->plan_expires_at;
        $plan = $customer->plan;
        $billing = app(BillingService::class);

        $renewal = $billing->submit($customer, $plan, ['payment_method_id' => $method->id, 'transaction_reference' => 'R-1']);
        $billing->approve($renewal, $admin);
        $this->assertTrue($customer->fresh()->plan_expires_at->equalTo($expiry->addMonthsNoOverflow(1)));

        $order = $billing->submit($customer, $plan, ['payment_method_id' => $method->id, 'transaction_reference' => 'R-2']);
        $this->actingAs($admin);
        Livewire::test(Orders::class)
            ->call('open', $order->id)
            ->call('reject')
            ->assertHasErrors('reason')
            ->set('reason', 'Payment not found')
            ->call('reject');

        $this->assertSame(OrderStatus::Rejected, $order->fresh()->status);
        $this->assertSame('Payment not found', $order->fresh()->admin_note);
    }

    #[Test]
    public function contact_fields_follow_the_settings(): void
    {
        $this->method();
        app(SettingsService::class)->set(['billing.contact_whatsapp' => 'hidden', 'billing.contact_phone' => 'required']);
        $customer = User::factory()->customer()->create(['phone' => null]);
        $plan = Plan::query()->where('slug', 'plan-2')->firstOrFail();
        $this->actingAs($customer);

        Livewire::test(Checkout::class, ['plan' => $plan])
            ->call('selectMethod', PaymentMethod::query()->value('id'))
            ->assertDontSee('WhatsApp number')
            ->set('transaction_reference', 'TX-1')->set('confirm', true)
            ->call('submit')
            ->assertHasErrors(['contact_phone' => 'required']);
    }

    // ------------------------------------------------------------------
    // Expiry
    // ------------------------------------------------------------------

    #[Test]
    public function expired_plans_fall_back_to_the_free_plan(): void
    {
        $expired = User::factory()->customer('plan-2')->create(['plan_expires_at' => now()->subHour()]);
        $soon = User::factory()->customer('plan-1')->create(['plan_expires_at' => now()->addDays(2)]);

        // Limits switch back immediately, even before the hourly command runs.
        $this->assertSame('Free', $expired->currentPlan()->name);

        $this->artisan('plans:expire')->assertSuccessful();

        $this->assertSame(Plan::fallback()->id, $expired->fresh()->plan_id);
        $this->assertNull($expired->fresh()->plan_expires_at);
        $this->assertNotNull($soon->fresh()->plan_reminder_sent_at);
        $this->assertSame(2, $expired->notifications()->count() + $soon->notifications()->count());
    }

    // ------------------------------------------------------------------
    // API and admin pages
    // ------------------------------------------------------------------

    #[Test]
    public function api_access_depends_on_the_plan(): void
    {
        $free = User::factory()->customer()->create();
        $pro = User::factory()->customer('plan-2')->create();
        TelegramBot::factory()->create(['user_id' => $pro->id, 'name' => 'Pro Bot']);
        TelegramBot::factory()->create(['user_id' => $free->id, 'name' => 'Free Bot']);

        $this->withToken($free->createToken('t')->plainTextToken)->getJson('/api/bots')->assertForbidden();
        $this->postJson('/api/auth/token', ['email' => $free->email, 'password' => 'password', 'device_name' => 'x'])->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->withToken($pro->createToken('t')->plainTextToken)->getJson('/api/bots')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Pro Bot');
    }

    #[Test]
    public function the_admin_pages_render_for_a_super_admin(): void
    {
        $this->method();
        $customer = User::factory()->customer()->create();
        PlanOrder::query()->create([
            'user_id' => $customer->id, 'plan_id' => Plan::query()->where('slug', 'plan-1')->value('id'), 'status' => OrderStatus::Pending,
            'amount_usd' => 5, 'transaction_reference' => 'ABC', 'payment_method_name' => 'USDT', 'contact_name' => 'Zara',
        ]);
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get('/admin/purchase-requests')->assertOk()->assertSee('Zara');
        $this->get('/admin/plans')->assertOk()->assertSee('Plan 3');
        $this->get('/admin/payment-methods')->assertOk()->assertSee('USDT (TRC20)');
        $this->get('/users')->assertOk()->assertSee('Free');
        $this->get('/settings?tab=billing')->assertOk()->assertSee('Registration & billing');
        $this->get('/pricing')->assertOk()->assertSee('$12');
    }
}
