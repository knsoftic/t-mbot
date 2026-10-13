<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Content;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function pages(): array
    {
        return collect([
            '/dashboard', '/bots', '/channels', '/groups', '/destinations', '/contents', '/contents/create', '/tags',
            '/campaigns', '/campaigns/create', '/schedules', '/logs', '/failed-jobs', '/audit-logs', '/users', '/roles',
            '/settings', '/system-health', '/profile', '/notifications',
        ])->mapWithKeys(fn ($url) => [$url => [$url]])->all();
    }

    #[Test]
    #[DataProvider('pages')]
    public function every_page_renders_for_a_super_admin(string $url): void
    {
        Http::fake(); // the health page pings Telegram

        $this->actingAs(User::factory()->superAdmin()->create())->get($url)->assertOk();
    }

    #[Test]
    public function record_pages_render(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $campaign = Campaign::factory()->withItems(2)->create();
        $content = Content::factory()->create();

        $this->get("/campaigns/{$campaign->id}")->assertOk()->assertSee($campaign->name);
        $this->get("/campaigns/{$campaign->id}/edit")->assertOk();
        $this->get("/contents/{$content->id}/edit")->assertOk()->assertSee($content->title);
    }

    #[Test]
    public function the_admin_role_cannot_manage_users_roles_or_settings(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/campaigns')->assertOk();
        $this->get('/bots')->assertOk();
        $this->get('/users')->assertForbidden();
        $this->get('/roles')->assertForbidden();
        $this->get('/settings')->assertForbidden();
        $this->get('/audit-logs')->assertForbidden();
    }

    #[Test]
    public function the_sidebar_only_shows_permitted_sections(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/dashboard')
            ->assertSee('Campaigns')
            ->assertDontSee('Administrators');
    }
}
