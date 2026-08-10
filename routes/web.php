<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

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
});

require __DIR__.'/settings.php';
