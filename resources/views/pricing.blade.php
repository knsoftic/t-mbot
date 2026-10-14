<x-guest-layout title="Plans & pricing" wide>
    <div class="text-center">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl dark:text-white">Plans & pricing</h1>
        <p class="mx-auto mt-2 max-w-2xl text-sm text-slate-500">Automate your Telegram channels and groups. Every account starts free; upgrade monthly when you need more bots, channels or posts. Prices in USD.</p>
    </div>

    <x-pricing-cards :plans="$plans" class="mt-8" />

    <p class="mt-8 text-center text-sm text-slate-500">
        @auth
            <a href="{{ route('billing.index') }}" class="font-medium text-brand-600 hover:text-brand-500">Go to Billing</a>
        @else
            <a href="{{ route('register') }}" class="font-medium text-brand-600 hover:text-brand-500">Create a free account</a>
            · <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:text-brand-500">Sign in</a>
        @endauth
    </p>
</x-guest-layout>
