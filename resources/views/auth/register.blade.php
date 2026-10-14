<x-guest-layout title="Create account">
    <h1 class="text-xl font-semibold text-slate-900 dark:text-white">Create your account</h1>
    <p class="mt-1 text-sm text-slate-500">Start free with one bot and one channel. Upgrade any time.</p>

    <div class="mt-4 rounded-lg bg-amber-50 p-3 text-xs text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30">
        <strong>Please provide correct and valid information.</strong> We use your e-mail and number to contact you about your account and plan purchases.
        <span class="block mt-0.5">Tamam maloomat sahi aur valid dein — isi par aap se rabta kiya jayega.</span>
    </div>

    <form method="POST" action="{{ route('register.store') }}" class="mt-5 space-y-4">
        @csrf
        <div class="hidden" aria-hidden="true">
            <label for="website">Website</label>
            <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
        </div>

        <div>
            <label for="name" class="label">Full name <span class="text-red-500">*</span></label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus autocomplete="name" maxlength="120"
                   class="input @error('name') input-error @enderror">
            @error('name') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="email" class="label">E-mail <span class="text-red-500">*</span></label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                   class="input @error('email') input-error @enderror">
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>

        @if ($phoneMode !== 'hidden')
            <div>
                <label for="phone" class="label">Mobile / WhatsApp number @if ($phoneMode === 'required')<span class="text-red-500">*</span>@endif</label>
                <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" @required($phoneMode === 'required') autocomplete="tel" placeholder="+92 300 1234567"
                       class="input @error('phone') input-error @enderror">
                <p class="mt-1 text-xs text-slate-500">With country code. Used for WhatsApp contact about your plan.</p>
                @error('phone') <p class="error">{{ $message }}</p> @enderror
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="password" class="label">Password <span class="text-red-500">*</span></label>
                <input id="password" name="password" type="password" required autocomplete="new-password"
                       class="input @error('password') input-error @enderror">
            </div>
            <div>
                <label for="password_confirmation" class="label">Confirm password <span class="text-red-500">*</span></label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="input">
            </div>
        </div>
        @error('password') <p class="error -mt-2">{{ $message }}</p> @enderror

        <label class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" name="confirm" value="1" class="checkbox mt-0.5" @checked(old('confirm'))>
            <span>I confirm that all the information I entered is correct and valid.</span>
        </label>
        @error('confirm') <p class="error -mt-2">{{ $message }}</p> @enderror

        <button type="submit" class="btn btn-primary w-full">Create account</button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-500">
        Already have an account? <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:text-brand-500">Sign in</a>
        · <a href="{{ route('pricing') }}" class="font-medium text-brand-600 hover:text-brand-500">See plans</a>
    </p>
</x-guest-layout>
