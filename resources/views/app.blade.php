<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'CRM Print') }}</title>
        <link rel="icon" type="image/png" href="/favicon.png">
        <meta name="description" content="CRM система оперативної поліграфії університету">
        <meta name="theme-color" content="#1D4289">
        {{-- Font preloading for performance --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap">
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @routes(nonce: \Illuminate\Support\Facades\Vite::cspNonce())
        @inertiaHead
    </head>
    <body class="antialiased bg-gray-50">
        @inertia
    </body>
</html>
