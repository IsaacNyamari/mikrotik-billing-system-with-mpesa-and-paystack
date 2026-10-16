<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <meta name="theme-color" content="#0b58ad">

        <title>{{ $title ?? __('My internet account') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">

        {{-- A subscriber reaches this with working internet (or mobile data), so the compiled
             stylesheet is safe, as on the pay page. --}}
        @fonts
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-slate-100 font-sans text-slate-700 antialiased">
        <main class="mx-auto flex min-h-screen w-full max-w-2xl flex-col px-4 py-6 sm:py-10">
            {{ $slot }}

            <p class="mt-auto pt-10 text-center text-xs text-slate-400">
                {{ __('Account portal by :platform', ['platform' => config('app.name')]) }}
            </p>
        </main>

        @livewireScripts
    </body>
</html>
