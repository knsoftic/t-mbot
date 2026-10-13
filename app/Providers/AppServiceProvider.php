<?php

namespace App\Providers;

use App\Models\User;
use App\Services\SettingsService;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: queue workers get fresh settings for every job.
        $this->app->scoped(SettingsService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureAuthorization();
        $this->configureRateLimiting();

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->mixedCase()->numbers()->uncompromised()
            : Password::min(8));

        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        if (! $this->app->runningUnitTests()) {
            config(['app.name' => $this->app->make(SettingsService::class)->appName()]);
        }
    }

    private function configureAuthorization(): void
    {
        // Super Admins can do everything; disabled users can do nothing.
        Gate::before(function (User $user) {
            if (! $user->isActive()) {
                return false;
            }

            return $user->isSuperAdmin() ? true : null;
        });

        foreach (array_keys(Permissions::definitions()) as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('api-auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('telegram-actions', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
    }
}
