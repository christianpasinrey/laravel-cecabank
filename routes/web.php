<?php

use Cpr\Cecabank\Http\Controllers\PaymentController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

$public = (array) config('cecabank.routes.public', []);
$callback = (array) config('cecabank.routes.callback', []);

// Browser-facing return URLs (URL_OK / URL_NOK).
// Use `web` middleware so flash session ('success'/'info'/'error') works,
// but CSRF must be off — Cecabank cannot send a token, and the routes are
// already authenticated by a TTL'd HMAC bound to the operation number.
Route::group([
    'prefix' => $public['prefix'] ?? 'payment',
    'middleware' => $public['middleware'] ?? ['web', 'throttle:60,1'],
    'as' => 'cecabank.',
], function () {
    Route::match(['get', 'post'], '/success', [PaymentController::class, 'success'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('success');
    Route::match(['get', 'post'], '/failure', [PaymentController::class, 'failure'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('failure');
});

// Server-to-server callback.
// Intentionally OUTSIDE the `web` middleware group: it doesn't need session
// or cookie encryption, and including `web` would activate VerifyCsrfToken
// which would 419 every legitimate Cecabank confirmation. The route is
// authenticated by Cecabank's SHA-256 signature, verified inside the
// controller, so even with no Laravel middleware the endpoint is safe.
$callbackPrefix = $callback['prefix'] ?? 'payment';
Route::post('/'.ltrim($callbackPrefix, '/').'/callback', [PaymentController::class, 'callback'])
    ->middleware($callback['middleware'] ?? ['throttle:120,1'])
    ->name('cecabank.callback');
