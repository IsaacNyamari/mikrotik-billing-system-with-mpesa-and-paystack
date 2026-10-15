<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">

        <title>{{ $title ?? __('Pay for your internet') }}</title>

        {{-- Reached from a text message on a phone with mobile data, so unlike the captive
             portal the compiled stylesheet is safe here. --}}
        @fonts
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-slate-100 font-sans text-slate-700 antialiased">
        <main class="mx-auto flex min-h-screen w-full max-w-md flex-col px-4 py-8">
            {{ $slot }}

            <p class="mt-auto pt-10 text-center text-xs text-slate-400">
                {{ __('Payments secured by :platform', ['platform' => config('app.name')]) }}
            </p>
        </main>

        @livewireScripts
    </body>
</html>
