# cpr/laravel-cecabank

[![Tests](https://github.com/christianpasinrey/laravel-cecabank/actions/workflows/tests.yml/badge.svg)](https://github.com/christianpasinrey/laravel-cecabank/actions/workflows/tests.yml)

Cecabank TPV (Virtual POS) integration for Laravel — **frontend-agnostic**.

The package ships the data model, signature engine, polymorphic transaction
log, lifecycle events and public callback routes. Everything user-facing
(admin CRUD, redirect page, sandbox UI) is the host's job: render it with
Blade, Inertia + Vue, Livewire, React… the package only hands you DTOs.

## Security at a glance

- Server-to-server callback authenticated by Cecabank's SHA-256 signature, verified with `hash_equals`.
- Browser return URLs authenticated by a TTL'd HMAC token bound to the operation number (`config('cecabank.return_token.ttl')`, default 30 min).
- All state transitions run inside `DB::transaction { lockForUpdate; … }` — concurrent callbacks cannot double-fulfil.
- `payable_type` / `payable_id` are intentionally NOT mass-assignable; use `PaymentTransaction::attachPayable($payable)`.
- The provider refuses to boot if `cecabank.urls.{test,production}` aren't `https://` to a `.ceca.es` host.
- The callback route lives OUTSIDE the `web` middleware group so CSRF can never reject a legitimate Cecabank confirmation.

See `SECURITY-AUDIT.md` for the full third-party review and the patches that addressed it.

## Install

```bash
composer require cpr/laravel-cecabank
php artisan vendor:publish --tag=cecabank-config
php artisan migrate
```

Optionally:
```bash
php artisan vendor:publish --tag=cecabank-views   # Blade auto-submit fallback
php artisan vendor:publish --tag=cecabank-lang    # Flash-message translations
```

## Make your host model payable

```php
use Cpr\Cecabank\Contracts\Payable;
use Cpr\Cecabank\Models\PaymentTransaction;

class Order extends Model implements Payable
{
    public function paymentAmount(): float        { return (float) $this->total; }
    public function paymentReference(): string    { return $this->order_number; }
    public function paymentDescription(): ?string { return "Order {$this->order_number}"; }
    public function isPayable(): bool             { return $this->status === 'pending_payment'; }
    public function paymentSuccessRoute(): string { return 'orders.index'; }
    public function paymentFailureRoute(): string { return 'orders.index'; }

    public function paymentTransactions()
    {
        return $this->morphMany(PaymentTransaction::class, 'payable');
    }
}
```

## Start a checkout

`Cecabank::checkout()` persists a pending `PaymentTransaction` and returns a
`CheckoutPayload` DTO with the fields and gateway URL. **You** decide how to
render the redirect.

### With Inertia + Vue
```php
use Cpr\Cecabank\Facades\Cecabank;

public function pay(Order $order)
{
    abort_unless($order->isPayable() && $this->ownsOrder($order), 403);

    $payload = Cecabank::checkout($order);

    return Inertia::render('Payment/Redirect', [
        'fields'     => $payload->fields,
        'gatewayUrl' => $payload->gatewayUrl,
        'reference'  => $order->paymentReference(),
    ]);
}
```

### With Blade (using the bundled view)
```php
return view('cecabank::redirect', [
    'fields' => $payload->fields,
    'action' => $payload->gatewayUrl,
    'title'  => 'Redirigiendo a la pasarela…',
]);
```

### As JSON (SPA / API)
```php
return response()->json($payload->toArray());
```

## React to payment lifecycle events

```php
use Cpr\Cecabank\Events\{PaymentCompleted, PaymentFailed, PaymentCanceled};

Event::listen(PaymentCompleted::class, function ($e) {
    $e->payable?->update(['status' => 'confirmed']);
});
```

## Routes the package owns

| Name | Verb | URI | Purpose |
| --- | --- | --- | --- |
| `cecabank.success` | GET/POST | `/payment/success` | URL_OK browser return — redirects to `Payable::paymentSuccessRoute()` |
| `cecabank.failure` | GET/POST | `/payment/failure` | URL_NOK browser return — redirects to `Payable::paymentFailureRoute()` |
| `cecabank.callback` | POST | `/payment/callback` | Server-to-server confirmation — returns `$*$OKY$*$` / `$*$NOK$*$` |

URI prefix and middleware are configurable in `config/cecabank.php`.

## Admin CRUD & sandbox

Out of scope for this package. You own the gateway CRUD UI; use
`Cpr\Cecabank\Models\PaymentGateway` directly. For sandbox flows the package
exposes:

```php
$payload = Cecabank::sandboxCheckout($gateway, 1.00, 'demo', 'test');
$preview = Cecabank::previewSandboxSignature($gateway, 1.00, 'test');
$tx      = Cecabank::reconcileSandboxReturn($operationNumber, $request->all(), success: true);
```

Wire your own admin sandbox return routes and point the package at them:

```php
// config/cecabank.php
'sandbox_return_routes' => [
    'ok'  => 'admin.cecabank.sandbox.return-ok',
    'nok' => 'admin.cecabank.sandbox.return-nok',
],
```

## Service API quick reference

All methods are exposed through the `Cecabank` facade
(`Cpr\Cecabank\CecabankService`):

```php
Cecabank::checkout($payable, ?$gateway = null): CheckoutPayload
Cecabank::sandboxCheckout($gateway, $amount, ?$description = null, ?$environment = null): SandboxPayload
Cecabank::previewSandboxSignature($gateway, $amount, ?$environment = null): array
Cecabank::reconcileSandboxReturn($operationNumber, $params, $success): ?PaymentTransaction
Cecabank::verifyCallbackSignature($params, $gateway, ?$environment = null): bool
Cecabank::sanitizeResponse($params): array
Cecabank::calculateSignature($data): string
Cecabank::returnToken($operationNumber): string
Cecabank::verifyReturnToken($operationNumber, $token): bool
Cecabank::amountToCents($amount): string
```

## Testing

```bash
composer install
vendor/bin/phpunit
```
