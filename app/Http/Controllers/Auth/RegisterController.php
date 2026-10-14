<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BillingService;
use App\Services\SettingsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Self-service sign-up: the account starts right away on the free plan.
 */
class RegisterController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function create(): View
    {
        abort_unless($this->settings->get('registration.enabled'), 404);

        return view('auth.register', ['phoneMode' => $this->phoneMode()]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->settings->get('registration.enabled'), 404);

        // Bots fill every field; people never see this one.
        abort_if(filled($request->input('website')), 422);

        $phoneMode = $this->phoneMode();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => $phoneMode === 'hidden'
                ? ['prohibited']
                : [$phoneMode === 'required' ? 'required' : 'nullable', 'string', 'max:30', 'regex:'.BillingService::PHONE_PATTERN],
            'password' => ['required', 'confirmed', Password::defaults()],
            'confirm' => ['accepted'],
        ], [
            'phone.regex' => 'Enter a valid mobile / WhatsApp number with country code, e.g. +92 300 1234567.',
            'confirm.accepted' => 'Please confirm that your information is correct and valid.',
        ]);

        $role = Role::query()->where('slug', Role::CUSTOMER)->first();

        if (! $role) {
            (new RolesAndPermissionsSeeder)->run();
            $role = Role::query()->where('slug', Role::CUSTOMER)->firstOrFail();
        }

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'whatsapp' => $data['phone'] ?? null,
            'password' => $data['password'],
            'role_id' => $role->id,
            'status' => UserStatus::Active,
        ]);

        $user->forceFill([
            'plan_id' => Plan::fallback()?->id,
            'plan_started_at' => now(),
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->audit->log('auth.registered', $user, "New account registered: {$user->email}", [], $user);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->flash('toast', ['message' => 'Welcome! Your account is ready on the free plan. Start by adding your bot.', 'type' => 'success']);

        return redirect()->route('dashboard');
    }

    private function phoneMode(): string
    {
        $mode = (string) $this->settings->get('registration.phone');

        return array_key_exists($mode, BillingService::FIELD_MODES) ? $mode : 'optional';
    }
}
