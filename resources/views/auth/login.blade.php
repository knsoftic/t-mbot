<x-guest-layout title="Sign in">
    <h1 class="text-xl font-semibold text-slate-900 dark:text-white">Sign in</h1>
    <p class="mt-1 text-sm text-slate-500">Welcome back. Sign in to manage your bots and campaigns.</p>

    @if (session('status'))
        <div class="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4">
        @csrf
        <div>
            <label for="email" class="label">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   class="input @error('email') input-error @enderror">
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div>
            <div class="flex items-center justify-between">
                <label for="password" class="label">Password</label>
                <a href="{{ route('password.request') }}" class="mb-1 text-xs font-medium text-brand-600 hover:text-brand-500">Forgot password?</a>
            </div>
            <input id="password" name="password" type="password" required autocomplete="current-password" class="input">
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" name="remember" value="1" class="checkbox"> Remember me
        </label>
        <button type="submit" class="btn btn-primary w-full">Sign in</button>
    </form>

    @if (app(\App\Services\SettingsService::class)->get('registration.enabled'))
        <p class="mt-6 text-center text-sm text-slate-500">
            New here? <a href="{{ route('register') }}" class="font-medium text-brand-600 hover:text-brand-500">Create a free account</a>
            · <a href="{{ route('pricing') }}" class="font-medium text-brand-600 hover:text-brand-500">See plans</a>
        </p>
    @endif
</x-guest-layout>
