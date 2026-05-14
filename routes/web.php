<?php

use Cpr\Cecabank\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

$public = config('cecabank.routes.public');

// Routes Cecabank itself calls: URL_OK / URL_NOK browser returns and the
// server-to-server callback. The host should NOT register anything under
// these route names; it can however change the URI prefix or middleware
// stack via config/cecabank.php.
Route::group([
    'prefix' => $public['prefix'] ?? 'payment',
    'middleware' => $public['middleware'] ?? ['web', 'throttle:60,1'],
    'as' => 'cecabank.',
], function () {
    Route::match(['get', 'post'], '/success', [PaymentController::class, 'success'])->name('success');
    Route::match(['get', 'post'], '/failure', [PaymentController::class, 'failure'])->name('failure');
    Route::post('/callback', [PaymentController::class, 'callback'])->name('callback');
});
