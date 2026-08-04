<?php

use App\Http\Controllers\PayPalController;
use Illuminate\Support\Facades\Route;

/**
 * PayPal checkout + webhook routes. Loaded from PayPalServiceProvider::boot()
 * (loadRoutesFrom), so they need no manual registration. They are API-style
 * (JSON, stateless), mounted without the web CSRF middleware — PayPal cannot
 * send a CSRF token to the webhook, and the checkout endpoints don't rely on
 * session cookies.
 *
 * The production checkout page (your Blade/JS at /fr/checkout/rapport-auto)
 * calls these; pass ?segment=<code> (or {"segment":...} in the body) to target a
 * market once more than one is live. It defaults to config('paypal.default_segment').
 */
Route::prefix('paypal')->name('paypal.')->group(function () {
    Route::post('create-setup-token', [PayPalController::class, 'createSetupToken'])->name('setup-token');
    Route::post('finalize',            [PayPalController::class, 'finalize'])->name('finalize');
    Route::post('create-wallet-order', [PayPalController::class, 'createWalletOrder'])->name('wallet-order');
    Route::post('capture-order',       [PayPalController::class, 'captureOrder'])->name('capture-order');
    Route::get('subscription',         [PayPalController::class, 'show'])->name('show');
    Route::post('cancel',              [PayPalController::class, 'cancel'])->name('cancel');
    // Pre-flight email availability check (called before charging).
    Route::get('precheck-email',       [PayPalController::class, 'precheckEmail'])->name('precheck-email');

    // Self-contained hosted-card-fields checkout page, used to validate the full
    // flow end-to-end. It only renders while the module is pointed at PayPal
    // SANDBOX (api_base contains "sandbox"); it 404s on live so it can never be
    // reached in production. Your real checkout UI lives on your own page.
    Route::get('sandbox', [PayPalController::class, 'sandboxPage'])->name('sandbox');

    // Inbound from PayPal — no CSRF, no auth. Register this URL in the dashboard.
    Route::post('webhook', [PayPalController::class, 'webhook'])->name('webhook')->withoutMiddleware([
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    ]);
});
