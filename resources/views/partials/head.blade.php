<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex, nofollow">
<title>{{ isset($title) && $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect width='24' height='24' rx='6' fill='%232577eb'/%3E%3Cpath d='M5 11.5 18 6l-2.2 12-4.3-3.3-2.2 2.1.3-3.3 5.6-5.2-6.9 4.3z' fill='white'/%3E%3C/svg%3E">
<script>
    // Apply the saved theme before first paint (no flash).
    (function () {
        try {
            var saved = localStorage.getItem('theme');
            var dark = saved ? saved === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', dark);
        } catch (e) {}
    })();
</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
