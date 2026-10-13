<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    #[Test]
    public function an_admin_can_log_in_and_out(): void
    {
        $user = User::factory()->superAdmin()->create(['password' => 'Secret-Pass-123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'Secret-Pass-123'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'user_id' => $user->id]);
    }

    #[Test]
    public function wrong_credentials_are_rejected_and_audited(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
    }

    #[Test]
    public function disabled_accounts_cannot_log_in_and_are_signed_out(): void
    {
        $user = User::factory()->superAdmin()->disabled()->create(['password' => 'Secret-Pass-123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'Secret-Pass-123'])->assertSessionHasErrors('email');
        $this->assertGuest();

        // A user disabled mid-session is logged out on the next request.
        $active = User::factory()->superAdmin()->create();
        $this->actingAs($active)->get('/dashboard')->assertOk();
        $active->update(['status' => 'disabled']);
        $this->get('/dashboard')->assertRedirect('/login');
    }

    #[Test]
    public function login_is_rate_limited(): void
    {
        $this->freezeTime();
        $user = User::factory()->superAdmin()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'Too many login attempts. Try again in 60 seconds.']);
    }

    #[Test]
    public function a_password_can_be_reset_by_email(): void
    {
        Notification::fake();
        $user = User::factory()->admin()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);

        $token = Password::createToken($user);
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Brand-New-Pass-99',
            'password_confirmation' => 'Brand-New-Pass-99',
        ])->assertRedirect('/login');

        $this->assertTrue(Hash::check('Brand-New-Pass-99', $user->refresh()->password));
    }

    #[Test]
    public function unknown_emails_get_the_same_reset_response(): void
    {
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status');
    }
}
