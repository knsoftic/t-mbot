<?php

namespace App\Livewire;

use App\Livewire\Concerns\InteractsWithUi;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Own profile, password change and personal API tokens (REST API / mobile apps).
 */
#[Title('Profile')]
class Profile extends Component
{
    use InteractsWithUi;

    public string $name = '';

    public string $email = '';

    public string $timezone = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $tokenName = '';

    /** Plain-text token, shown exactly once after creation. */
    public ?string $newToken = null;

    public function mount(): void
    {
        $user = auth()->user();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->timezone = (string) $user->timezone;
    }

    public function saveProfile(AuditLogger $audit): void
    {
        $user = auth()->user();

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'timezone' => ['nullable', 'timezone:all'],
        ]);

        $user->fill(['name' => $this->name, 'email' => $this->email, 'timezone' => $this->timezone ?: null])->save();
        $audit->log('user.profile_updated', $user, 'Profile updated', $audit->changes($user));
        $this->toast('Profile saved.');
    }

    public function changePassword(AuditLogger $audit): void
    {
        $user = auth()->user();

        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ]);

        $user->forceFill(['password' => $this->password, 'password_changed_at' => now()])->save();

        // Sign out other browser sessions; keep this one.
        Auth::logoutOtherDevices($this->password);
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete();

        $audit->log('user.password_changed', $user, 'Password changed');
        $this->reset(['current_password', 'password', 'password_confirmation']);
        $this->toast('Password changed. Other sessions were signed out.');
    }

    public function createToken(AuditLogger $audit): void
    {
        $this->validate(['tokenName' => ['required', 'string', 'max:60']], [], ['tokenName' => 'token name']);

        $token = auth()->user()->createToken($this->tokenName);
        $this->newToken = $token->plainTextToken;
        $audit->log('api.token_created', auth()->user(), "API token “{$this->tokenName}” created");
        $this->tokenName = '';
    }

    public function revokeToken(int $id, AuditLogger $audit): void
    {
        $token = auth()->user()->tokens()->whereKey($id)->first();

        if ($token) {
            $token->delete();
            $audit->log('api.token_revoked', auth()->user(), "API token “{$token->name}” revoked");
            $this->toast('Token revoked.');
        }
    }

    public function dismissToken(): void
    {
        $this->newToken = null;
    }

    public function render()
    {
        return view('livewire.profile', [
            'tokens' => auth()->user()->tokens()->latest()->get(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }
}
