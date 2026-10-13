<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Jobs\ProcessCampaignJob;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Models\User;
use App\Services\CampaignService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_token_can_be_issued_and_used(): void
    {
        $user = User::factory()->admin()->create(['password' => 'Secret-Pass-123']);

        $token = $this->postJson('/api/auth/token', ['email' => $user->email, 'password' => 'Secret-Pass-123', 'device_name' => 'phone'])
            ->assertCreated()->json('token');

        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('email', $user->email);
        $this->postJson('/api/auth/token', ['email' => $user->email, 'password' => 'wrong', 'device_name' => 'x'])->assertUnprocessable();
    }

    #[Test]
    public function requests_without_a_token_are_rejected(): void
    {
        $this->getJson('/api/bots')->assertUnauthorized();
    }

    #[Test]
    public function the_api_never_returns_bot_tokens(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $bot = TelegramBot::factory()->create(['token' => '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw']);

        $response = $this->getJson('/api/bots')->assertOk()->assertJsonPath('data.0.masked_token', '123456789:••••••••••');
        $this->assertStringNotContainsString('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw', $response->getContent());
        $this->assertStringNotContainsString('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw', $this->getJson("/api/bots/{$bot->id}")->getContent());
    }

    #[Test]
    public function campaigns_can_be_created_and_controlled(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->admin()->create());
        $destination = TelegramDestination::factory()->create();
        $content = Content::factory()->create();

        $id = $this->postJson('/api/campaigns', [
            'name' => 'API campaign',
            'bot_id' => $destination->bot_id,
            'destination_id' => $destination->id,
            'posting_order' => 'sequential',
            'trigger_type' => 'daily',
            'timezone' => 'Asia/Karachi',
            'repeat_mode' => 'none',
            'failure_behavior' => 'retry_then_continue',
            'max_retries' => 3,
            'retry_delay_seconds' => 60,
            'items' => [['content_id' => $content->id]],
            'slots' => [['time' => '10:00']],
            'activate' => true,
        ])->assertCreated()->assertJsonPath('data.status', 'running')->json('data.id');

        $this->postJson("/api/campaigns/{$id}/pause")->assertOk()->assertJsonPath('data.status', 'paused');
        $this->postJson("/api/campaigns/{$id}/resume")->assertOk()->assertJsonPath('data.status', 'running');
        $this->postJson("/api/campaigns/{$id}/run")->assertAccepted();
        Queue::assertPushed(ProcessCampaignJob::class);
        $this->postJson("/api/campaigns/{$id}/stop")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/campaigns/{$id}/pause")->assertUnprocessable();
        $this->getJson("/api/campaigns/{$id}/logs")->assertOk();
    }

    #[Test]
    public function permissions_are_enforced(): void
    {
        $role = Role::create(['name' => 'Viewer', 'slug' => 'viewer']);
        $role->permissions()->sync(Permission::query()->whereIn('slug', ['campaigns.view'])->pluck('id'));
        (new RolesAndPermissionsSeeder)->run();
        $role->permissions()->sync(Permission::query()->whereIn('slug', ['campaigns.view'])->pluck('id'));
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id]));

        $campaign = Campaign::factory()->withItems(1)->create();
        app(CampaignService::class)->activate($campaign);

        $this->getJson("/api/campaigns/{$campaign->id}")->assertOk();
        $this->postJson("/api/campaigns/{$campaign->id}/pause")->assertForbidden();
        $this->getJson('/api/bots')->assertForbidden();
        $this->assertSame(CampaignStatus::Running, $campaign->refresh()->status);
    }

    #[Test]
    public function content_media_urls_are_validated(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/contents', ['title' => 'x', 'type' => 'image', 'source' => 'url', 'media_url' => 'http://127.0.0.1/a.jpg'])
            ->assertUnprocessable()->assertJsonValidationErrors('media_url');

        $this->postJson('/api/contents', ['title' => 'Video', 'type' => 'video', 'source' => 'url', 'media_url' => 'https://cdn.example.org/v.mp4', 'tags' => ['promo']])
            ->assertCreated()->assertJsonPath('data.tags.0', 'promo');
    }
}
