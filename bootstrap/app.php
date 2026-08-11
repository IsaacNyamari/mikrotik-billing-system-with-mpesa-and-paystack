<?php

use App\Http\Middleware\EnsureSubscriptionIsActive;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Runs on every web request so the tenant is bound before any tenant-owned model is
        // touched, including by Livewire components and route model binding.
        $middleware->web(append: [
            ResolveTenant::class,
        ]);

        // Ordering is load-bearing, not cosmetic. Route model binding resolves {customer} with
        // a scoped query, so the tenant has to be known before SubstituteBindings runs —
        // otherwise strict scoping rejects the lookup and every detail page 500s.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenant::class,
        );

        // An ISP that has not paid for the platform cannot use it. Registered as an alias
        // rather than appended to the web group, because it applies to the console only —
        // the captive portal and the callbacks are public and must keep working whatever the
        // ISP owes the platform.
        $middleware->alias([
            'subscribed' => EnsureSubscriptionIsActive::class,
        ]);

        // Safaricom posts callbacks from its own servers and has no session or CSRF token.
        // The unguessable token in the URL is what authenticates the request instead.
        $middleware->validateCsrfTokens(except: [
            'webhooks/mpesa/*',
            'webhooks/paystack',
            'webhooks/platform/mpesa/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
