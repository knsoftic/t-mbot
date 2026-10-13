<?php

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Enums\DestinationType;
use App\Jobs\TestDestinationJob;
use App\Livewire\Destinations\Index;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Models\User;
use App\Services\DestinationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeTelegram;
use Tests\TestCase;

class DestinationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_admin_can_add_a_channel_by_public_link(): void
    {
        $bot = TelegramBot::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Index::class)
            ->call('create')
            ->set('form.bot_id', (string) $bot->id)
            ->set('form.name', 'My Channel')
            ->set('form.chat_id', 'https://t.me/my_channel')
            ->set('form.type', 'channel')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('telegram_destinations', ['chat_id' => '@my_channel', 'bot_id' => $bot->id, 'type' => 'channel']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'destination.created']);
    }

    #[Test]
    public function invalid_chat_ids_are_rejected(): void
    {
        $bot = TelegramBot::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Index::class)
            ->call('create')
            ->set('form.bot_id', (string) $bot->id)
            ->set('form.name', 'x')
            ->set('form.chat_id', 'not a chat!')
            ->call('save')
            ->assertHasErrors('form.chat_id');
    }

    #[Test]
    public function the_test_button_queues_a_test_job(): void
    {
        Queue::fake();
        $destination = TelegramDestination::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Index::class)->call('test', $destination->id);

        Queue::assertPushed(TestDestinationJob::class, fn ($job) => $job->destinationId === $destination->id && $job->sendMessage);
        $this->assertSame(ConnectionStatus::Testing, $destination->refresh()->connection_status);
    }

    #[Test]
    public function verification_sends_a_test_message_and_stores_the_numeric_chat_id(): void
    {
        $telegram = FakeTelegram::install();
        $telegram->respondNext('getChat', ['id' => -1001234567890, 'type' => 'channel', 'title' => 'Real Channel', 'username' => 'my_channel']);
        $destination = TelegramDestination::factory()->create(['chat_id' => '@my_channel']);

        $result = app(DestinationService::class)->verify($destination);

        $this->assertTrue($result['ok']);
        $destination->refresh();
        $this->assertSame('-1001234567890', $destination->chat_id);
        $this->assertSame('Real Channel', $destination->telegram_title);
        $this->assertTrue($destination->bot_can_post);
        $this->assertSame(ConnectionStatus::Connected, $destination->connection_status);
        $this->assertSame(['getMe', 'getChat', 'getChatMember', 'sendMessage'], $telegram->methods());
    }

    #[Test]
    public function a_bot_without_post_permission_in_a_channel_fails_with_instructions(): void
    {
        $telegram = FakeTelegram::install();
        $telegram->respondNext('getChat', ['id' => -1001234567890, 'type' => 'channel', 'title' => 'Channel']);
        $telegram->respondNext('getChatMember', ['status' => 'member', 'user' => ['id' => 7000001]]);
        $destination = TelegramDestination::factory()->create();

        $result = app(DestinationService::class)->verify($destination);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('administrator', $result['message']);
        $this->assertSame(ConnectionStatus::Failed, $destination->refresh()->connection_status);
        $this->assertNotContains('sendMessage', $telegram->methods(), 'No test message when the bot cannot post');
    }

    #[Test]
    public function a_group_upgraded_to_a_supergroup_is_followed(): void
    {
        $telegram = FakeTelegram::install();
        $telegram->failNext('getChat', 400, 'Bad Request: group chat was upgraded to a supergroup chat', ['migrate_to_chat_id' => -1009876543210]);
        $telegram->respondNext('getChat', ['id' => -1009876543210, 'type' => 'supergroup', 'title' => 'Group']);
        $destination = TelegramDestination::factory()->create(['chat_id' => '-123456', 'type' => DestinationType::Group]);

        $this->assertTrue(app(DestinationService::class)->verify($destination, sendMessage: false)['ok']);
        $this->assertSame('-1009876543210', $destination->refresh()->chat_id);
        $this->assertSame(DestinationType::Supergroup, $destination->type);
    }

    #[Test]
    public function channels_and_groups_pages_are_filtered_by_type(): void
    {
        TelegramDestination::factory()->create(['name' => 'Alpha Channel']);
        TelegramDestination::factory()->group()->create(['name' => 'Beta Group']);
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/channels')->assertOk()->assertSee('Alpha Channel')->assertDontSee('Beta Group');
        $this->get('/groups')->assertOk()->assertSee('Beta Group')->assertDontSee('Alpha Channel');
    }
}
