<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out users whose account was disabled after they logged in.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $user->currentAccessToken()?->delete();

                return response()->json(['message' => 'Your account is disabled.'], 403);
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Your account has been disabled.']);
        }

        return $next($request);
    }
}
