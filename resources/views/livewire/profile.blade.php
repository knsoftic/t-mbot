<div>
    <x-page-header title="Profile" description="Your account, password and API tokens." />

    <div class="grid gap-6 lg:grid-cols-2">
        <form wire:submit="saveProfile" class="card space-y-4 p-6">
            <h2 class="card-title">Account</h2>
            <x-field label="Name" for="name" required><input id="name" type="text" wire:model="name" class="input"></x-field>
            <x-field label="E-mail" for="email" required><input id="email" type="email" wire:model="email" class="input"></x-field>
            <x-field label="Timezone" for="timezone" hint="Dates in the panel are shown in this timezone.">
                <select id="timezone" wire:model="timezone" class="input">
                    <option value="">System default</option>
                    @foreach ($timezones as $tz) <option value="{{ $tz }}">{{ $tz }}</option> @endforeach
                </select>
            </x-field>
            <div class="flex justify-end"><button type="submit" class="btn btn-primary">Save profile</button></div>
        </form>

        <form wire:submit="changePassword" class="card space-y-4 p-6">
            <h2 class="card-title">Change password</h2>
            <x-field label="Current password" for="current_password" required><input id="current_password" type="password" wire:model="current_password" class="input" autocomplete="current-password"></x-field>
            <x-field label="New password" for="password" required><input id="password" type="password" wire:model="password" class="input" autocomplete="new-password"></x-field>
            <x-field label="Confirm new password" for="password_confirmation" required><input id="password_confirmation" type="password" wire:model="password_confirmation" class="input" autocomplete="new-password"></x-field>
            <div class="flex justify-end"><button type="submit" class="btn btn-primary">Change password</button></div>
        </form>

        <section class="card p-6 lg:col-span-2">
            <h2 class="card-title">API tokens</h2>
            <p class="mt-1 text-sm text-slate-500">For the REST API (<span class="kbd-pill">{{ url('/api') }}</span>), e.g. a mobile app. Send it as <span class="kbd-pill">Authorization: Bearer &lt;token&gt;</span>. A token has the same permissions as your account.</p>

            @if ($newToken)
                <div class="mt-4 rounded-lg bg-emerald-50 p-4 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:ring-emerald-500/30" x-data="{ copied: false }">
                    <div class="text-sm font-semibold text-emerald-900 dark:text-emerald-200">Copy your new token now — it will not be shown again.</div>
                    <div class="mt-2 flex gap-2">
                        <input type="text" readonly value="{{ $newToken }}" class="input font-mono text-xs" x-ref="tok">
                        <button type="button" class="btn btn-secondary" x-on:click="navigator.clipboard.writeText($refs.tok.value); copied = true"><span x-text="copied ? 'Copied' : 'Copy'"></span></button>
                        <button type="button" wire:click="dismissToken" class="btn btn-ghost">Done</button>
                    </div>
                </div>
            @endif

            <form wire:submit="createToken" class="mt-4 flex gap-2">
                <input type="text" wire:model="tokenName" class="input sm:w-72" placeholder="Token name, e.g. Android app">
                <button type="submit" class="btn btn-primary">Create token</button>
            </form>
            @error('tokenName') <p class="error">{{ $message }}</p> @enderror

            <ul class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($tokens as $token)
                    <li class="flex items-center justify-between py-2.5 text-sm" wire:key="t-{{ $token->id }}">
                        <div>
                            <div class="font-medium">{{ $token->name }}</div>
                            <div class="text-xs text-slate-500">Created {{ display_time($token->created_at, 'M j, Y') }} · last used {{ $token->last_used_at ? relative_time($token->last_used_at) : 'never' }}</div>
                        </div>
                        <x-confirm action="revokeToken({{ $token->id }})" class="btn btn-ghost btn-sm text-red-600" title="Revoke token?" message="Apps using this token will lose access immediately." confirm-text="Revoke">Revoke</x-confirm>
                    </li>
                @empty
                    <li class="py-3 text-sm text-slate-500">No API tokens.</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
