@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="h-full bg-slate-100 font-sans text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-200">
    <div class="flex min-h-full flex-col items-center justify-center px-4 py-12">
        <div class="mb-8 flex items-center gap-3">
            <x-brand-mark size="size-11" />
            <div>
                <div class="text-lg font-semibold text-slate-900 dark:text-white">{{ config('app.name') }}</div>
                <div class="text-xs text-slate-500">Telegram auto-posting & automation</div>
            </div>
        </div>
        <div class="card w-full max-w-md p-6 sm:p-8">
            {{ $slot }}
        </div>
        <p class="mt-6 text-xs text-slate-400">&copy; {{ date('Y') }} {{ config('app.name') }}</p>
    </div>
</body>
</html>
