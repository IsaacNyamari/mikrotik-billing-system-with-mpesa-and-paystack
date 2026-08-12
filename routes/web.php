<?php

use App\Http\Controllers\HotspotPageController;
use App\Http\Controllers\MpesaCallbackController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\PlatformMpesaCallbackController;
use App\Http\Controllers\PlatformPaystackWebhookController;
use App\Http\Controllers\RouterProvisionController;
use Illuminate\Support\Facades\Route;

// The public site. Anyone reading these has internet, so unlike the captive portal these
// pages are free to use the compiled stylesheet.
Route::livewire('/', 'pages::frontend.home')->name('home');
Route::livewire('about', 'pages::frontend.about')->name('about');
Route::livewire('services', 'pages::frontend.services')->name('services');
Route::livewire('contact', 'pages::frontend.contact')->name('contact');
Route::livewire('pricing', 'pages::frontend.services')->name('pricing');

// Public, unauthenticated: a customer sitting on a HotSpot before they have paid for anything.
// The portal key identifies which router — and so which ISP — they landed from.
Route::livewire('p/{portalKey}', 'pages::portal.index')->name('portal');

// Fetched by the router itself during hotspot:install-portal, then served from its own
// storage to customers.
Route::get('p/{portalKey}/mikrotik/{page}', HotspotPageController::class)
    ->whereIn('page', array_keys(HotspotPageController::PAGES))
    ->name('portal.mikrotik');

// Safaricom posts here. The token in the path is the only credential; CSRF is exempted for it
// in bootstrap/app.php.
Route::post('webhooks/mpesa/{token}', MpesaCallbackController::class)->name('webhooks.mpesa');

// Paystack signs every webhook, so this one needs no secret in its path — the signature is
// checked against the paying ISP's own key.
Route::post('webhooks/paystack', PaystackWebhookController::class)->name('webhooks.paystack');

// A router fetching its own configuration, before it has a tunnel or any credentials to
// authenticate with. The token in the path is the only guard, and it is single-use.
Route::get('provision/{token}', RouterProvisionController::class)
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->name('routers.provision-script');

// An ISP paying the platform, rather than a customer paying an ISP. Its own endpoint and its
// own token: the tenant callback looks payments up in a table this money never touches.
Route::post('webhooks/platform/mpesa/{token}', PlatformMpesaCallbackController::class)
    ->name('webhooks.platform-mpesa');

// The same, for an ISP paying their subscription through Paystack. Signed, so it needs no
// token in the path — and checked against the platform's key rather than any ISP's.
Route::post('webhooks/platform/paystack', PlatformPaystackWebhookController::class)
    ->name('webhooks.platform-paystack');

// Reachable however much an ISP owes — locking someone out of the page where they pay turns
// every late payment into a phone call. The manual is here for the same reason: half of what
// it explains is read by somebody trying to get an account back in order.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('billing', 'pages::billing.index')->name('billing.index');

    Route::livewire('docs', 'pages::docs.index')->name('docs.index');
});

Route::middleware(['auth', 'verified', 'subscribed'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    Route::livewire('mikrotik-monitor', 'pages::monitor.index')->name('monitor.index');

    // "create" is declared before the wildcard so it is not swallowed by {customer}.
    Route::livewire('customers', 'pages::customers.index')->name('customers.index');
    Route::livewire('customers/create', 'pages::customers.form')->name('customers.create');
    Route::livewire('customers/{customer}', 'pages::customers.show')->name('customers.show');
    Route::livewire('customers/{customer}/edit', 'pages::customers.form')->name('customers.edit');

    Route::livewire('routers', 'pages::routers.index')->name('routers.index');
    Route::livewire('routers/create', 'pages::routers.form')->name('routers.create');
    Route::livewire('routers/{router}', 'pages::routers.show')->name('routers.show');
    Route::livewire('routers/{router}/edit', 'pages::routers.form')->name('routers.edit');

    // The one thing that cannot be done over the API, because a router behind NAT has no API
    // to do it over. Carried by a person and pasted into the device.
    Route::livewire('routers/{router}/setup', 'pages::routers.setup')->name('routers.setup');

    Route::livewire('plans', 'pages::plans.index')->name('plans.index');
    Route::livewire('plans/create', 'pages::plans.form')->name('plans.create');
    Route::livewire('plans/{plan}/edit', 'pages::plans.form')->name('plans.edit');

    // Platform super admins belong to no ISP, so they pick one here before any tenant-scoped
    // screen has anything to show.
    Route::livewire('select-isp', 'pages::tenants.select')->name('tenants.select');

    // Runs the platform's own billing. Guarded inside the component too — a route name is not
    // an authorization check.
    Route::livewire('platform/subscriptions', 'pages::platform.subscriptions')->name('platform.subscriptions');

    Route::livewire('reports/earnings', 'pages::reports.earnings')->name('reports.earnings');
    Route::livewire('settings/mpesa', 'pages::settings.mpesa')->name('settings.mpesa');
    Route::livewire('settings/portal', 'pages::settings.portal')->name('settings.portal');
});

require __DIR__.'/settings.php';
