<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

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
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
