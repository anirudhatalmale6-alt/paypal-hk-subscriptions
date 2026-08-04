<?php

use App\Http\Controllers\PayPalController;
use Illuminate\Support\Facades\Route;

/**
 * PayPal checkout + webhook routes. Load these from RouteServiceProvider (or
 * bootstrap/app.php in Laravel 11+). They are API-style (JSON, stateless), so
 * mount them without the web CSRF middleware — or, if you keep them on the web
 * group, add 'paypal/webhook' to VerifyCsrfToken::$except (PayPal cannot send a
 * CSRF token).
 *
 * The front-end checkout page (your Blade/JS at /fr/checkout/rapport-auto) calls
 * these; pass ?segment=<code> (or {"segment":...} in the body) to target a market
 * once more than one is live. It defaults to config('paypal.default_segment').
 */
Route::prefix('paypal')->name('paypal.')->group(function () {
    Route::post('create-setup-token', [PayPalController::class, 'createSetupToken'])->name('setup-token');
    Route::post('finalize',            [PayPalController::class, 'finalize'])->name('finalize');
    Route::post('create-wallet-order', [PayPalController::class, 'createWalletOrder'])->name('wallet-order');
    Route::post('capture-order',       [PayPalController::class, 'captureOrder'])->name('capture-order');
    Route::get('subscription',         [PayPalController::class, 'show'])->name('show');
    Route::post('cancel',              [PayPalController::class, 'cancel'])->name('cancel');

    // Inbound from PayPal — no CSRF, no auth. Register this URL in the dashboard.
    Route::post('webhook', [PayPalController::class, 'webhook'])->name('webhook')->withoutMiddleware([
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    ]);
});
