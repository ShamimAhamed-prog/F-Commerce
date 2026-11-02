<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    {{-- UI-1: viewport-fit=cover for notches / iOS home indicator --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    {{-- Apply the saved theme before first paint (no flash). Nonce keeps the strict CSP intact. --}}
    <script nonce="{{ Vite::cspNonce() }}">try{var t=localStorage.getItem('fc:ui:theme');if(t==='light'||t==='dark')document.documentElement.dataset.theme=t}catch(e){}</script>
    <title inertia>{{ config('app.name') }}</title>

    {{-- PWA-6: manifest on every layout, incl. auth pages --}}
    <link rel="manifest" href="{{ config('deployment.base_path') }}/manifest.webmanifest">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#f3f5fb">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0a0f1f">
    <meta name="app-base" content="{{ config('deployment.base_path') }}">
    <meta name="csp-nonce" content="{{ Vite::cspNonce() }}">
    <meta name="color-scheme" content="light dark">

    {{-- PWA-7: iOS --}}
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="apple-touch-icon" href="{{ config('deployment.base_path') }}/icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ config('deployment.base_path') }}/icons/favicon-32.png">

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body class="bg-surface text-ink antialiased">
    @inertia
    <noscript>This application requires JavaScript.</noscript>
</body>
</html>
