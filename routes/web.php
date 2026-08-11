<?php

use App\Http\Controllers\HotspotPageController;
use App\Http\Controllers\MpesaCallbackController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Public, unauthenticated: a customer sitting on a HotSpot before they have paid for anything.
// The portal key identifies which router — and so which ISP — they landed from.
Route::livewire('p/{portalKey}', 'pages::portal.index')->name('portal');

// Fetched by the router itself during hotspot:install-portal, then served from its own
// storage to customers.
Route::get('p/{portalKey}/mikrotik/{page}', HotspotPageController::class)
    ->whereIn('page', ['login.html', 'alogin.html'])
    ->name('portal.mikrotik');

// Safaricom posts here. The token in the path is the only credential; CSRF is exempted for it
// in bootstrap/app.php.
Route::post('webhooks/mpesa/{token}', MpesaCallbackController::class)->name('webhooks.mpesa');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // "create" is declared before the wildcard so it is not swallowed by {customer}.
    Route::livewire('customers', 'pages::customers.index')->name('customers.index');
    Route::livewire('customers/create', 'pages::customers.form')->name('customers.create');
    Route::livewire('customers/{customer}', 'pages::customers.show')->name('customers.show');
    Route::livewire('customers/{customer}/edit', 'pages::customers.form')->name('customers.edit');

    Route::livewire('routers', 'pages::routers.index')->name('routers.index');
    Route::livewire('routers/create', 'pages::routers.form')->name('routers.create');
    Route::livewire('routers/{router}', 'pages::routers.show')->name('routers.show');
    Route::livewire('routers/{router}/edit', 'pages::routers.form')->name('routers.edit');

    Route::livewire('plans', 'pages::plans.index')->name('plans.index');
    Route::livewire('plans/create', 'pages::plans.form')->name('plans.create');
    Route::livewire('plans/{plan}/edit', 'pages::plans.form')->name('plans.edit');

    // Platform super admins belong to no ISP, so they pick one here before any tenant-scoped
    // screen has anything to show.
    Route::livewire('select-isp', 'pages::tenants.select')->name('tenants.select');

    Route::livewire('reports/earnings', 'pages::reports.earnings')->name('reports.earnings');
    Route::livewire('settings/mpesa', 'pages::settings.mpesa')->name('settings.mpesa');
});

require __DIR__.'/settings.php';
