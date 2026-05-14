<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cecabank gateway endpoints
    |--------------------------------------------------------------------------
    */
    'urls' => [
        'test' => env('CECABANK_TEST_URL', 'https://tpv.ceca.es/tpvweb/tpv/compra.action'),
        'production' => env('CECABANK_PROD_URL', 'https://pgw.ceca.es/tpvweb/tpv/compra.action'),
    ],

    'exponent' => '2',
    'supported_payment' => 'SSL',
    'cipher' => 'SHA2',

    /*
    |--------------------------------------------------------------------------
    | Public routes (URL_OK / URL_NOK / callback)
    |--------------------------------------------------------------------------
    |
    | These are owned by the package because Cecabank itself calls them.
    | Adjust the prefix and middleware here.
    |
    */
    'routes' => [
        'public' => [
            'prefix' => 'payment',
            'middleware' => ['web', 'throttle:60,1'],
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
