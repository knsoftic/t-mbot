<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function visitors_see_the_landing_page_with_plans(): void
    {
        $this->seed(PlansSeeder::class);
        PaymentMethod::query()->create(['name' => 'USDT (TRC20)', 'address' => 'TAddr', 'is_active' => true]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Quick Send')
            ->assertSee('Simple monthly pricing')
            ->assertSee('$12')
            ->assertSee('currently USDT (TRC20)')
            ->assertSee(route('register'))
            ->assertSee('index, follow', false);
    }

    #[Test]
    public function signed_in_users_go_to_the_dashboard_and_sign_up_links_follow_the_setting(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/')->assertRedirect(route('dashboard'));

        auth()->logout();
        app(SettingsService::class)->set(['registration.enabled' => false]);

        $this->get('/')->assertOk()->assertDontSee(route('register'));
    }
}
