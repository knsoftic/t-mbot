@php
    $app = config('app.name');
    $startUrl = $registration ? route('register') : route('login');
    $startLabel = $registration ? 'Start free' : 'Sign in';
    $features = [
        ['bot', 'Your own bots', 'Connect any number of bots from @BotFather. Tokens are encrypted and never shown again.'],
        ['megaphone', 'Channels & groups', 'Add channels, groups and supergroups. A connection test checks the bot is an admin that can post.'],
        ['send', 'Quick Send', 'Save a bot + chat once, then just type and press Send — the message is posted right away.'],
        ['calendar', 'Campaigns & schedules', 'Post every few minutes, at fixed times, daily, weekly or on exact dates — and repeat automatically.'],
        ['photo', 'Content library', 'Text, images, videos, documents, audio and GIFs with HTML formatting and a live Telegram preview.'],
        ['sparkles', 'AI post writer', 'Draft posts and captions in English, Urdu, Roman Urdu or Hindi with an AI that runs on our own server (selected plans).'],
        ['shield', 'Reliable delivery', 'Automatic retries, Telegram flood-limit handling and crash recovery that never posts the same item twice.'],
        ['list', 'Logs & alerts', 'Every post is logged with Telegram\'s answer. Get alerts when a campaign finishes, fails or a bot disconnects.'],
    ];
    $faqs = [
        ['Do I need any technical knowledge?', 'No. You create a bot with @BotFather in Telegram (takes a minute), paste its token, add the bot as an admin of your channel, and you are ready. The built-in User Guide walks you through every step.'],
        ['Whose bot sends the messages?', 'Yours. Posts are sent through the bots you connect, so they appear in your channels and groups under your bot\'s name.'],
        ['How do I pay for a plan?', 'Plans are monthly and priced in USD. At checkout you choose a payment method'.($paymentMethods->isNotEmpty() ? ' (currently '.$paymentMethods->implode(', ').')' : '').', pay, and send us the transaction ID. We verify the payment by hand and activate your plan — you get a notification.'],
        ['What happens when I reach my posting limit?', 'Nothing is lost. Scheduled posts wait and continue automatically when your daily, weekly or monthly limit resets — or you can upgrade at any time.'],
        ['Can I cancel?', 'There is no subscription to cancel: if you do not renew, your account simply returns to the free plan when the month ends. Your bots, content and campaigns stay.'],
        ['Is my bot token safe?', 'Tokens are encrypted in the database, hidden from every page and API response, and removed from logs.'],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    @include('partials.head', [
        'title' => 'Telegram auto-posting & automation',
        'robots' => 'index, follow',
        'metaDescription' => $app.' — schedule posts, run campaigns and send messages instantly to your Telegram channels and groups with your own bots. Start free.',
    ])
</head>
<body class="bg-white font-sans text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-200">
    {{-- Header --}}
    <header class="sticky top-0 z-30 border-b border-slate-200/70 bg-white/80 backdrop-blur dark:border-slate-800 dark:bg-slate-950/80">
        <div class="mx-auto flex h-16 max-w-6xl items-center gap-6 px-4 sm:px-6">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                <x-brand-mark size="size-9" />
                <span class="text-base font-semibold text-slate-900 dark:text-white">{{ $app }}</span>
            </a>
            <nav class="hidden items-center gap-6 text-sm font-medium text-slate-600 md:flex dark:text-slate-300">
                <a href="#features" class="hover:text-slate-900 dark:hover:text-white">Features</a>
                <a href="#how" class="hover:text-slate-900 dark:hover:text-white">How it works</a>
                <a href="#pricing" class="hover:text-slate-900 dark:hover:text-white">Pricing</a>
                <a href="#faq" class="hover:text-slate-900 dark:hover:text-white">FAQ</a>
            </nav>
            <div class="ml-auto flex items-center gap-2">
                <a href="{{ route('login') }}" class="btn btn-ghost hidden sm:inline-flex">Sign in</a>
                @if ($registration)
                    <a href="{{ route('register') }}" class="btn btn-primary">Start free</a>
                @endif
                <details class="relative md:hidden">
                    <summary class="btn-icon list-none" aria-label="Menu"><x-icon name="menu" class="size-6" /></summary>
                    <div class="absolute right-0 mt-2 w-48 rounded-xl bg-white p-2 shadow-lg ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                        @foreach (['#features' => 'Features', '#how' => 'How it works', '#pricing' => 'Pricing', '#faq' => 'FAQ'] as $href => $label)
                            <a href="{{ $href }}" class="block rounded-lg px-3 py-2 text-sm hover:bg-slate-50 dark:hover:bg-slate-800">{{ $label }}</a>
                        @endforeach
                        <a href="{{ route('login') }}" class="block rounded-lg px-3 py-2 text-sm hover:bg-slate-50 dark:hover:bg-slate-800">Sign in</a>
                    </div>
                </details>
            </div>
        </div>
    </header>

    <main>
        {{-- Hero --}}
        <section class="relative overflow-hidden">
            <div class="pointer-events-none absolute inset-x-0 -top-40 -z-10 h-[32rem] bg-gradient-to-b from-brand-100/70 to-transparent dark:from-brand-500/10"></div>
            <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:py-24">
                <div>
                    <span class="inline-flex items-center gap-2 rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/30">
                        <x-icon name="send" class="size-3.5" /> Telegram marketing, on autopilot
                    </span>
                    <h1 class="mt-5 text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl dark:text-white">
                        Post to all your Telegram channels <span class="text-brand-600 dark:text-brand-400">without lifting a finger</span>
                    </h1>
                    <p class="mt-5 max-w-xl text-lg leading-relaxed text-slate-600 dark:text-slate-300">
                        Schedule posts, run repeating campaigns and send messages instantly to your channels and groups — through your own bots, from one simple dashboard.
                    </p>
                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        <a href="{{ $startUrl }}" class="btn btn-primary px-6 py-3 text-base">{{ $startLabel }} <x-icon name="chevron-right" class="size-4" /></a>
                        <a href="#pricing" class="btn btn-secondary px-6 py-3 text-base">See pricing</a>
                    </div>
                    <p class="mt-4 text-sm text-slate-500">Free plan included · Monthly plans in USD · No contract</p>
                </div>

                {{-- Product illustration (pure HTML) --}}
                <div class="relative">
                    <div class="card overflow-hidden p-0 shadow-2xl shadow-brand-900/10">
                        <div class="flex items-center gap-2 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                            <span class="size-2.5 rounded-full bg-red-400"></span><span class="size-2.5 rounded-full bg-amber-400"></span><span class="size-2.5 rounded-full bg-emerald-400"></span>
                            <span class="ml-2 text-xs font-medium text-slate-500">Quick Send</span>
                        </div>
                        <div class="grid gap-4 p-5 sm:grid-cols-5">
                            <div class="space-y-2 sm:col-span-2">
                                @foreach ([['megaphone', 'Main channel', true], ['user-group', 'Buyers group', false], ['megaphone', 'Offers channel', false]] as [$icon, $name, $active])
                                    <div @class(['flex items-center gap-2 rounded-lg px-3 py-2 text-sm', 'bg-brand-50 font-medium text-brand-800 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-100 dark:ring-brand-500/30' => $active, 'text-slate-600 dark:text-slate-300' => ! $active])>
                                        <x-icon :name="$icon" class="size-4" /> {{ $name }}
                                    </div>
                                @endforeach
                            </div>
                            <div class="sm:col-span-3">
                                <div class="rounded-lg bg-slate-50 p-3 text-sm text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800/60 dark:text-slate-200 dark:ring-slate-700">
                                    🔥 Weekend sale: 30% off everything until Sunday night. Tap the link in our bio!
                                </div>
                                <div class="mt-3 flex items-center justify-between">
                                    <span class="text-xs text-slate-400">Ctrl + Enter</span>
                                    <span class="btn btn-primary btn-sm pointer-events-none"><x-icon name="send" class="size-3.5" /> Send</span>
                                </div>
                                <div class="mt-3 flex items-center gap-2 rounded-lg bg-emerald-50 p-2.5 text-xs font-medium text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">
                                    <x-icon name="check-circle" class="size-4" /> Sent to Main channel
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card absolute -bottom-10 left-6 hidden w-56 p-3 shadow-xl sm:block">
                        <div class="flex items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-200"><x-icon name="calendar" class="size-4 text-brand-600" /> Next scheduled post</div>
                        <div class="mt-1 text-xs text-slate-500">Daily tips · today 18:00</div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Features --}}
        <section id="features" class="scroll-mt-20 border-t border-slate-100 bg-slate-50/60 py-20 dark:border-slate-900 dark:bg-slate-900/40">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">Everything you need to grow on Telegram</h2>
                    <p class="mt-3 text-slate-600 dark:text-slate-300">From one-click messages to fully automated posting schedules.</p>
                </div>
                <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($features as [$icon, $title, $text])
                        <div class="card p-5">
                            <span class="flex size-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300"><x-icon :name="$icon" class="size-5" /></span>
                            <h3 class="mt-4 text-sm font-semibold text-slate-900 dark:text-white">{{ $title }}</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ $text }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- How it works --}}
        <section id="how" class="scroll-mt-20 py-20">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">Up and running in minutes</h2>
                    <p class="mt-3 text-slate-600 dark:text-slate-300">Three steps from sign-up to your first post.</p>
                </div>
                <ol class="mt-12 grid gap-6 md:grid-cols-3">
                    @foreach ([
                        ['Create your account', 'Sign up free with your name, e-mail and WhatsApp number. Your account is ready immediately.'],
                        ['Connect a bot and a channel', 'Create a bot with @BotFather, paste its token, add the bot as an admin of your channel or group and press Test.'],
                        ['Post', 'Type a message in Quick Send and press Send, or build a campaign that posts on its own schedule.'],
                    ] as $i => [$title, $text])
                        <li class="relative rounded-2xl p-6 ring-1 ring-slate-200 dark:ring-slate-800">
                            <span class="flex size-9 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-white">{{ $i + 1 }}</span>
                            <h3 class="mt-4 font-semibold text-slate-900 dark:text-white">{{ $title }}</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ $text }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- Pricing --}}
        <section id="pricing" class="scroll-mt-20 border-t border-slate-100 bg-slate-50/60 py-20 dark:border-slate-900 dark:bg-slate-900/40">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">Simple monthly pricing</h2>
                    <p class="mt-3 text-slate-600 dark:text-slate-300">Start free. Upgrade when you need more bots, channels or posts. Prices in USD.</p>
                </div>
                @if ($plans->isNotEmpty())
                    <x-pricing-cards :plans="$plans" class="mt-12" />
                @endif
            </div>
        </section>

        {{-- FAQ --}}
        <section id="faq" class="scroll-mt-20 py-20">
            <div class="mx-auto max-w-3xl px-4 sm:px-6">
                <h2 class="text-center text-3xl font-bold tracking-tight text-slate-900 dark:text-white">Questions & answers</h2>
                <div class="mt-10 divide-y divide-slate-200 rounded-2xl ring-1 ring-slate-200 dark:divide-slate-800 dark:ring-slate-800">
                    @foreach ($faqs as [$question, $answer])
                        <details class="group px-5 py-4">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-medium text-slate-900 dark:text-white">
                                {{ $question }}
                                <x-icon name="chevron-down" class="size-5 shrink-0 text-slate-400 transition group-open:rotate-180" />
                            </summary>
                            <p class="mt-3 text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ $answer }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Final call to action --}}
        <section class="px-4 pb-20 sm:px-6">
            <div class="mx-auto max-w-6xl overflow-hidden rounded-3xl bg-brand-600 px-6 py-14 text-center text-white sm:px-12">
                <h2 class="text-3xl font-bold tracking-tight">Ready to put your channels on autopilot?</h2>
                <p class="mx-auto mt-3 max-w-xl text-brand-100">Create your free account and send your first post in a few minutes.</p>
                <a href="{{ $startUrl }}" class="btn mt-8 bg-white px-6 py-3 text-base text-brand-700 hover:bg-brand-50">{{ $registration ? 'Create free account' : 'Sign in' }}</a>
            </div>
        </section>
    </main>

    <footer class="border-t border-slate-200 py-8 dark:border-slate-800">
        <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-4 text-sm text-slate-500 sm:flex-row sm:px-6">
            <div class="flex items-center gap-2"><x-brand-mark size="size-6" /> &copy; {{ date('Y') }} {{ $app }}</div>
            <div class="flex gap-5">
                <a href="{{ route('pricing') }}" class="hover:text-slate-900 dark:hover:text-white">Pricing</a>
                <a href="{{ route('login') }}" class="hover:text-slate-900 dark:hover:text-white">Sign in</a>
                @if ($registration)
                    <a href="{{ route('register') }}" class="hover:text-slate-900 dark:hover:text-white">Create account</a>
                @endif
            </div>
        </div>
    </footer>
</body>
</html>
