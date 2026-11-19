<?php

use App\Http\Controllers\Auth\MicrosoftOAuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// ─── Public / Auth ────────────────────────────────────────────────────────────

Route::get('/', fn () => view('welcome'))->name('home');
Route::get('/login', fn () => redirect()->route('home'))->name('login');

// Throttle OAuth entry: max 15 login attempts per minute per IP.
// Callback is also throttled to prevent token-stuffing / redirect-loop abuse.
Route::get('/auth/microsoft', [MicrosoftOAuthController::class, 'redirectToMicrosoft'])->name('auth.microsoft')->middleware('throttle:15,1');
Route::get('/auth/microsoft/callback', [MicrosoftOAuthController::class, 'handleMicrosoftCallback'])->name('auth.microsoft.callback')->middleware('throttle:15,1');
Route::post('/logout', [MicrosoftOAuthController::class, 'logout'])->name('logout');

// ─── Authenticated ────────────────────────────────────────────────────────────

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/home', fn () => redirect()->route('dashboard'));

    // Placeholder module routes (coming soon)
    Route::redirect('/quotations', '/modules/project-management/quotations')->name('quotations.index');
    Route::get('/purchase-orders', fn () => 'Purchase Order Module - Coming Soon')->name('purchase-orders.index');
    Route::get('/inventory', fn () => 'Inventory Module - Coming Soon')->name('inventory.index');
    Route::get('/vendors', fn () => 'Vendors Module - Coming Soon')->name('vendors.index');
    Route::get('/customers', fn () => 'Customers Module - Coming Soon')->name('customers.index');
    Route::get('/reports', fn () => 'Reports Module - Coming Soon')->name('reports.index');
});

// ─── Grouped route files ──────────────────────────────────────────────────────

require __DIR__.'/partials/masters.php';
require __DIR__.'/partials/modules.php';
require __DIR__.'/partials/settings.php';
