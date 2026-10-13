<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $throttleKey = Str::transliterate(Str::lower($credentials['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            event(new Lockout($request));

            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 60);
            $this->audit->log('auth.login_failed', null, 'Failed login for '.$credentials['email']);

            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->isActive()) {
            Auth::logout();
            $this->audit->log('auth.login_blocked', $user, 'Login blocked: account disabled', [], $user);

            throw ValidationException::withMessages(['email' => 'Your account has been disabled. Contact an administrator.']);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $this->audit->log('auth.login', $user, 'Logged in', [], $user);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $this->audit->log('auth.logout', $user, 'Logged out', [], $user);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
