<x-guest-layout title="Reset password">
    <h1 class="text-xl font-semibold text-slate-900 dark:text-white">Choose a new password</h1>

    <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label for="email" class="label">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required class="input @error('email') input-error @enderror">
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password" class="label">New password</label>
            <input id="password" name="password" type="password" required autocomplete="new-password" class="input @error('password') input-error @enderror">
            @error('password') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password_confirmation" class="label">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="input">
        </div>
        <button type="submit" class="btn btn-primary w-full">Reset password</button>
    </form>
</x-guest-layout>
