<x-guest-layout title="Forgot password">
    <h1 class="text-xl font-semibold text-slate-900 dark:text-white">Forgot your password?</h1>
    <p class="mt-1 text-sm text-slate-500">Enter your e-mail address and we will send you a reset link.</p>

    @if (session('status'))
        <div class="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
        @csrf
        <div>
            <label for="email" class="label">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus class="input @error('email') input-error @enderror">
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn btn-primary w-full">Send reset link</button>
        <a href="{{ route('login') }}" class="block text-center text-sm text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">Back to sign in</a>
    </form>
</x-guest-layout>
