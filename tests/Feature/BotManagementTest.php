<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\ConnectionStatus;
use App\Livewire\Bots\Index;
use App\Models\Campaign;
use App\Models\TelegramBot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeTelegram;
use Tests\TestCase;

class BotManagementTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

    #[Test]
    public function an_admin_can_add_a_bot_and_its_token_is_verified_and_encrypted(): void
    {
        $telegram = FakeTelegram::install();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'Marketing Bot')
            ->set('token', self::TOKEN)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDontSee('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw');

        $bot = TelegramBot::query()->sole();
        $this->assertSame('fake_test_bot', $bot->username);
        $this->assertSame(ConnectionStatus::Connected, $bot->connection_status);
        $this->assertSame(self::TOKEN, $bot->token);
        $this->assertSame(['getMe'], $telegram->methods());

        $raw = \DB::table('telegram_bots')->value('token');
        $this->assertStringNotContainsString('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw', $raw, 'Tokens must be encrypted at rest');
        $this->assertDatabaseHas('audit_logs', ['action' => 'bot.created']);
    }

    #[Test]
    public function the_token_format_is_validated(): void
    {
        FakeTelegram::install();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'Bad')
            ->set('token', 'not-a-token')
            ->call('save')
            ->assertHasErrors('token');

        $this->assertSame(0, TelegramBot::query()->count());
    }

    #[Test]
    public function the_same_token_cannot_be_added_twice(): void
    {
        FakeTelegram::install();
        TelegramBot::factory()->create(['token' => self::TOKEN]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'Duplicate')
            ->set('token', self::TOKEN)
            ->call('save')
            ->assertHasErrors('token');
    }

    #[Test]
    public function an_invalid_token_is_saved_as_failed_with_a_clear_message(): void
    {
        $telegram = FakeTelegram::install();
        $telegram->failNext('getMe', 401, 'Unauthorized');
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'Revoked')
            ->set('token', self::TOKEN)
            ->call('save')
            ->assertDispatched('toast', type: 'warning');

        $bot = TelegramBot::query()->sole();
        $this->assertSame(ConnectionStatus::Failed, $bot->connection_status);
        $this->assertStringContainsString('invalid or was revoked', $bot->last_error);
    }

    #[Test]
    public function a_bot_used_by_an_active_campaign_cannot_be_deleted(): void
    {
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Running]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Index::class)->call('delete', $campaign->bot_id)->assertDispatched('toast', type: 'error');
        $this->assertNotSoftDeleted($campaign->bot);
    }

    #[Test]
    public function the_bot_list_never_contains_the_token(): void
    {
        $bot = TelegramBot::factory()->create(['token' => self::TOKEN]);
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/bots')->assertOk()->assertSee($bot->name)->assertDontSee('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw');
    }
}
