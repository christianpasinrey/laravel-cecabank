<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cecabank gateway endpoints
    |--------------------------------------------------------------------------
    |
    | These URLs are where the client browser is POSTed with signed card data.
    | The service provider refuses to boot if either URL doesn't match
    | `allowed_url_host_suffixes` below or doesn't use https — defense against
    | a leaked / mis-set env redirecting card-bearing traffic to an attacker
    | origin.
    |
    */
    'urls' => [
        'test' => env('CECABANK_TEST_URL', 'https://tpv.ceca.es/tpvweb/tpv/compra.action'),
        'production' => env('CECABANK_PROD_URL', 'https://pgw.ceca.es/tpvweb/tpv/compra.action'),
    ],

    'allowed_url_host_suffixes' => ['.ceca.es'],

    'exponent' => '2',
    'supported_payment' => 'SSL',
    'cipher' => 'SHA2',

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | `public` is the browser-facing pair (URL_OK / URL_NOK landings). They
    | live inside the `web` middleware group so flash messages work, but
    | VerifyCsrfToken is dropped per route because Cecabank can't send a CSRF
    | token (the routes are authenticated by an HMAC return token instead).
    |
    | `callback` is the server-to-server confirmation. Intentionally OUTSIDE
    | `web` — no session, no CSRF, no cookie encryption. Authenticated by
    | Cecabank's SHA-256 signature, verified in the controller.
    |
    | Both stacks rate-limit themselves; separate buckets so a browser-side
    | burst can never starve legitimate server-to-server confirmations.
    |
    */
    'routes' => [
        'public' => [
            'prefix' => 'payment',
            'middleware' => ['web', 'throttle:60,1'],
        ],
        'callback' => [
            'prefix' => 'payment',
            'middleware' => ['throttle:120,1'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback redirect routes
    |--------------------------------------------------------------------------
    |
    | Used when a transaction has no payable record (e.g. sandbox or orphan).
    | For regular flows the Payable contract supplies per-record routes.
    |
    */
    'fallback_routes' => [
        'success' => 'home',
        'failure' => 'home',
    ],

    /*
    |--------------------------------------------------------------------------
    | URL_OK / URL_NOK return token
    |--------------------------------------------------------------------------
    |
    | The token attached to URL_OK / URL_NOK query strings is a signed envelope
    | binding (operationNumber, issuedAt) under app.key. `ttl` is the maximum
    | age (seconds) a token will be honored; older tokens are rejected so a
    | leaked Referer / log line stops being a permanent skeleton key.
    |
    */
    'return_token' => [
        'ttl' => 1800,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sandbox return routes
    |--------------------------------------------------------------------------
    |
    | Route names (defined by the host) that Cecabank should redirect to after
    | a sandbox payment. The package only generates the absolute URLs; the
    | host implements the controllers and calls
    | `Cecabank::reconcileSandboxReturn(...)` from them.
    |
    */
    'sandbox_return_routes' => [
        'ok' => env('CECABANK_SANDBOX_RETURN_OK', ''),
        'nok' => env('CECABANK_SANDBOX_RETURN_NOK', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Test cards (for sandbox UI hints)
    |--------------------------------------------------------------------------
    */
    'test_cards' => [
        ['pan' => '5540500001000004', 'brand' => 'Mastercard'],
        ['pan' => '5020470001370055', 'brand' => 'Maestro'],
        ['pan' => '5020080001000006', 'brand' => 'Maestro'],
        ['pan' => '4507670001000009', 'brand' => 'Visa'],
    ],
    'test_cvv' => '989',

];
