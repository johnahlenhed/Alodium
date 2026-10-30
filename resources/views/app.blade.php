<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Light or dark before the first paint, so the page never flashes the wrong theme.
             Same rule as resources/js/utils/theme.js: the stored choice, else the OS setting. --}}
        <script>
            (function () {
                var stored = null;
                try { stored = localStorage.getItem('alodium-theme'); } catch (e) {}
                var dark = stored === 'dark' || (stored !== 'light' && !!window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
            })();
        </script>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @if (config('sentry.dsn'))
            {{-- Read by resources/js/app.jsx. Only present in production (see config/sentry.php). --}}
            <meta name="sentry-dsn" content="{{ config('sentry.dsn') }}">
            <meta name="sentry-environment" content="{{ config('sentry.environment') ?? app()->environment() }}">
            <meta name="sentry-traces-sample-rate" content="{{ config('sentry.traces_sample_rate') }}">
        @endif

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body class="bg-canvas font-sans text-gray-900 antialiased">
        @inertia
    </body>
</html>
