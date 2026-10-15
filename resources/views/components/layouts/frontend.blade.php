<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ?? __('ISP billing and MikroTik management') }} &mdash; {{ config('app.name') }}</title>

        <meta name="description" content="{{ $description ?? __('Sell wifi, manage PPPoE subscribers and control your MikroTik routers from one place. Pay as you earn.') }}">
        <meta name="theme-color" content="#0b58ad">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- Unlike the captive portal, everyone reading this page already has internet, so the
             compiled stylesheet is safe here. --}}
        @fonts
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-white font-sans text-slate-700 antialiased selection:bg-mint-300/60 selection:text-brand-950">
        <x-frontend.nav />

        <main>
            {{ $slot }}
        </main>

        <x-frontend.footer />

        @livewireScripts
    </body>
</html>
