# Security Audit — cpr/laravel-cecabank v0.1.2

## Executive summary

The package gets the **cryptographic primitives right** (SHA-256 signature, `hash_equals`, HMAC return token bound to APP_KEY) but ships **three high-risk issues stemming from the public routing surface**: the server-to-server `POST /payment/callback` is registered under the `web` middleware group with CSRF enabled (which will reject every Cecabank confirmation once a host runs `php artisan optimize` or otherwise enforces CSRF), `PaymentCompleted` can be dispatched twice under concurrent callbacks because the state transition has no DB lock, and the URL_OK/URL_NOK HMAC token is unbounded in time so a single Referer/log leak grants permanent access to a transaction's landing page.

Counts: **2 Critical · 4 High · 5 Medium · 4 Low/Info**.

---

## Findings

### CRITICAL

#### C-1: Server-to-server callback registered under CSRF-enabled `web` middleware
- **File**: `routes/web.php:14,19` and `config/cecabank.php:31`
- **Description**: The callback is `Route::post('/callback', …)` inside a group whose default middleware is `['web', 'throttle:60,1']`. The `web` group in Laravel 11/12 still includes `\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken` (registered via `bootstrap/app.php` in fresh apps). Cecabank does not (and cannot) send an `X-CSRF-TOKEN` header or `_token` field, so every legitimate confirmation will return **HTTP 419 / TokenMismatchException** as soon as the host adds the standard `withMiddleware` CSRF block — which the Laravel 12 skeleton ships by default. The package gives no documented way to except `/payment/callback` from CSRF.
- **Attack scenario**: Confirmations never reach `PaymentController::callback`, so a real successful payment is never marked `completed`, the merchant fulfils nothing, and the buyer disputes the charge. This is denial-of-service against legitimate flows, plus the URL_OK landing happily shows "payment is being verified" to the user — masking the failure indefinitely.
- **Recommended fix**: Stop reusing the `web` group for the callback. Register a dedicated stack and document a host hook to except the path. Either:
  ```php
  // routes/web.php
  Route::post('/callback', [PaymentController::class, 'callback'])
      ->middleware(config('cecabank.routes.callback.middleware', ['throttle:60,1']))
      ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
      ->name('callback');
  ```
  and in the service provider document `$middleware->validateCsrfTokens(except: ['payment/callback'])` for the host. Default config should NOT contain `web` for `/callback`.
- **Regression test**: `POST /payment/callback` with no `_token` and `Cookie` session must return 200 with body `$*$NOK$*$` (or `$*$OKY$*$`), never 419.

#### C-2: Race condition on concurrent callbacks → duplicate `PaymentCompleted` dispatch
- **File**: `src/Http/Controllers/PaymentController.php:77-122`
- **Description**: `callback()` does `where('operation_number', …)->first()`, checks `status === 'completed'` outside a transaction, then `update(['status' => 'completed', …])`. If Cecabank retries (it does — server retries on 5xx and on slow responses) two requests can both read `status = 'pending'`, both pass signature verification, both write `completed`, and both fire `PaymentCompleted::dispatch(...)`. Host listeners then double-fulfil the order, double-send the receipt e-mail, or double-credit a wallet.
- **Attack scenario**: Not exploit-driven — happens naturally during Cecabank gateway retries or when the merchant server is briefly slow. Same issue allows a **replay attack**: anyone who captured the original signed POST (egress proxy, WAF log) can replay it at will because the package never invalidates the signed payload after first use; replay during the brief window before `status='completed'` is committed will fire the event twice.
- **Recommended fix**: Wrap the state transition in a DB transaction with a row-level lock and only fire the event when the UPDATE actually transitioned the row:
  ```php
  $completed = DB::transaction(function () use ($operationNumber, $params, /* … */) {
      $tx = PaymentTransaction::where('operation_number', $operationNumber)
          ->lockForUpdate()->first();
      if (! $tx || $tx->status !== 'pending') {
          return null; // idempotent: already finalised
      }
      $tx->update(['status' => 'completed', /* … */]);
      return $tx;
  });
  if ($completed) {
      PaymentCompleted::dispatch($completed, $completed->payableRecord());
  }
  ```
- **Regression test**: Spin two concurrent callbacks for the same `operation_number`; assert exactly one `Event::fake()`-captured `PaymentCompleted` and that `status` is `completed` exactly once in DB.

---

### HIGH

#### H-1: Return-token HMAC has no expiry, no use-once, no IP/UA binding
- **File**: `src/CecabankService.php:263-275`
- **Description**: `returnToken()` is `hash_hmac('sha256', $operationNumber, app.key)`. It never expires, never rotates, has no `nbf/exp`, and is not invalidated after first use. The token travels in the URL query string of `URL_OK`/`URL_NOK`, so it ends up in: browser history, browser autofill, `Referer` header on every outbound link from the host's "thank you" page (Google Analytics, Sentry, Intercom widget, etc.), reverse-proxy access logs (nginx logs full query strings by default), and any CDN in front of the success page.
- **Attack scenario**: Anyone who recovers the token (log access, shoulder-surfing, screen recording, Sentry breadcrumb leak) can hit `/payment/success?Num_operacion=…&token=…` forever. While `success()` does not change DB state, the `failure()` handler **does** — it updates a `pending` transaction to `canceled` and fires `PaymentCanceled`. If a buyer's payment was actually still pending at the gateway (browser closed before Cecabank decided), an attacker with the token can force the package to mark it `canceled` even though Cecabank will later confirm it via the server-to-server callback. That confirmation then fails the `$transaction->status === 'pending'` invariant inside `callback()` — payment stays `canceled` in our DB while the buyer's card is charged.
- **Recommended fix**: Include creation-time and operation-number in the signed payload, with a hard TTL. The token must be a small signed envelope, not a raw HMAC:
  ```php
  public function returnToken(string $operationNumber): string
  {
      $payload = $operationNumber.'|'.time();
      $mac = hash_hmac('sha256', $payload, (string) config('app.key'));
      return rtrim(strtr(base64_encode($payload.'|'.$mac), '+/', '-_'), '=');
  }

  public function verifyReturnToken(string $operationNumber, string $token): bool
  {
      $raw = base64_decode(strtr($token, '-_', '+/'), true);
      if ($raw === false) { return false; }
      $parts = explode('|', $raw, 3);
      if (count($parts) !== 3) { return false; }
      [$op, $ts, $mac] = $parts;
      if (! hash_equals($operationNumber, $op)) { return false; }
      if ((time() - (int) $ts) > 1800) { return false; } // 30 min TTL
      $expected = hash_hmac('sha256', $op.'|'.$ts, (string) config('app.key'));
      return hash_equals($expected, $mac);
  }
  ```
  And: `failure()` must only transition to `canceled` if the server-to-server callback hasn't already moved the row out of `pending` (the lockForUpdate from C-2 covers it).
- **Regression test**: A token issued > 30 min ago is rejected; replaying a valid token after the underlying transaction was `completed` by the callback does **not** flip the row to `canceled`.

#### H-2: `success()` returns the same redirect whether the token is valid or not
- **File**: `src/Http/Controllers/PaymentController.php:29-44`
- **Description**: When `verifyReturnToken()` fails, `resolveTransaction()` returns `null`, so `$transaction` is null. The code then falls through to `redirect()->route($route)->with('info', …)` using `config('cecabank.fallback_routes.success')` — the same redirect a legitimate user gets. There is no distinction between "valid token, payment still pending" and "forged token". An attacker can probe `/payment/success?Num_operacion=anything&token=anything` and get the same UX as a paying customer, then phish or social-engineer using a real-looking screenshot.
- **Attack scenario**: Crafted "you paid us, thanks" page reachable without any token check. Combined with the lack of CSRF on this GET, an external site can embed `<img src=".../payment/success?…">` to confuse session/flash state in older middleware stacks.
- **Recommended fix**: When token verification fails, redirect to the **failure** fallback (or abort 404), never to the success fallback. Do not flash `info` messages on invalid tokens:
  ```php
  if (! $transaction) {
      return redirect()->route(config('cecabank.fallback_routes.failure'))
          ->with('error', __('cecabank::messages.payment_failed'));
  }
  ```
- **Regression test**: `GET /payment/success?Num_operacion=OPxxx&token=garbage` redirects to the failure route, not success.

#### H-3: `payable_type` is mass-assignable and resolved polymorphically
- **File**: `src/Models/PaymentTransaction.php:17-34,60-70`
- **Description**: `payable_type` and `payable_id` are inside `$fillable`. The package itself only sets them via trusted code paths, but any host that calls `PaymentTransaction::create($request->all())` (admin tool, importer, queue payload) lets an attacker pick an arbitrary Eloquent class name. `payable()` is a `MorphTo`, so accessing `$transaction->payable` will `new $class` and run `find($payable_id)`. Combined with Laravel's morph map being opt-in, an attacker can target sensitive classes (`App\Models\User`, `Laravel\Passport\Token`, …) and use the polymorphic relation as an oracle ("does row #N exist?"), and any custom model with side-effecting accessors will fire on read.
- **Attack scenario**: Mass-assignment via a host endpoint that forwards request input. The `payableRecord()` accessor then instantiates that class on every callback / success page render.
- **Recommended fix**: Remove the polymorphic fields from `$fillable` and provide an explicit setter, plus enforce a morph map at boot:
  ```php
  protected $fillable = [
      'payment_gateway_id', 'operation_number', 'amount', 'status',
      'environment', 'is_sandbox', 'authorization_number', 'reference',
      'signature_sent', 'signature_response', 'raw_request', 'raw_response',
      'error_message', 'completed_at',
  ];

  public function attachPayable(Payable $payable): void
  {
      $this->payable()->associate($payable)->save();
  }
  ```
  In the service provider boot, call `Relation::enforceMorphMap([...])` or at least document that the host MUST register a morph map and that `payable_type` is **not** mass-assignable.
- **Regression test**: `PaymentTransaction::create(['payable_type' => User::class, 'payable_id' => 1, …])` raises `MassAssignmentException`.

#### H-4: Cecabank gateway URL is mutable via env without TLS/host validation
- **File**: `config/cecabank.php:11-12`
- **Description**: `CECABANK_TEST_URL` and `CECABANK_PROD_URL` are read straight from env. Any host that misconfigures `.env` (typo, attacker with env access) can redirect the **client browser** (POST form submission in `redirect.blade.php`) to an arbitrary URL — including HTTP or attacker-controlled origin — while keeping `MerchantID`, signed `Firma` and amount intact. Because the user is the one POSTing, no CORS/SOP saves them; the attacker phishes card data.
- **Attack scenario**: Supply-chain or env-leak attacker rewrites the URL; the package never validates the value is an HTTPS Cecabank-owned host.
- **Recommended fix**: Validate the URL at boot:
  ```php
  // CecabankServiceProvider::boot()
  foreach (['test', 'production'] as $env) {
      $url = (string) config("cecabank.urls.{$env}");
      $host = parse_url($url, PHP_URL_HOST);
      $scheme = parse_url($url, PHP_URL_SCHEME);
      if ($scheme !== 'https' || ! str_ends_with((string) $host, '.ceca.es')) {
          throw new \RuntimeException("Refusing to load: cecabank.urls.{$env} must be an https://*.ceca.es URL.");
      }
  }
  ```
- **Regression test**: Booting with `CECABANK_PROD_URL=http://evil.example.com` throws at provider boot.

---

### MEDIUM

#### M-1: Predictable `operation_number` enables targeted gateway-side replay window
- **File**: `src/CecabankService.php:306-309`
- **Description**: `'OP'.$payable->getKey().'T'.time()`. Both components are observable / guessable (sequential primary keys and seconds-resolution timestamps). The Cecabank pre-image is `key|MID|BIN|TID|operationNumber|amount|…`; an attacker who can compute the signature (they cannot without the key) is unaffected, but an attacker who **observed** an old signed POST can craft a `URL_OK` hit (no key needed there if H-1 isn't fixed) for any predicted `operation_number`. Also, two simultaneous checkouts for the same payable in the same second collide and break the `unique` index on `operation_number`.
- **Recommended fix**: `'OP'.$payable->getKey().'-'.Str::random(12)` or include `random_bytes(8)`. Predictability provides no upside.
- **Regression test**: 1000 calls produce 1000 distinct operation numbers; format remains ≤ 50 chars.

#### M-2: `PaymentGateway::active()` silently picks the first row when several are active
- **File**: `src/Models/PaymentGateway.php:84-87`
- **Description**: `firstOrFail()` on `is_active = true`. If two rows are accidentally active (admin UI bug, seeder, race), the package picks "the first ID" with no warning. The wrong merchant gets the money and the wrong encryption key signs the pre-image (verification fails on callback, payment captured anyway).
- **Recommended fix**: `where('is_active', true)->limit(2)->get()` and throw if more than one. Alternatively change the migration to add `->unique(['is_active'])` with a partial index (PostgreSQL) or enforce in app: `sole()` instead of `firstOrFail()`.
- **Regression test**: With two active gateways, `PaymentGateway::active()` throws `MultipleRecordsFoundException`.

#### M-3: `throttle:60,1` applied to the callback can drop confirmations
- **File**: `routes/web.php:14`
- **Description**: All Cecabank confirmations come from the same source IP. 60 req/min for the **combined** success+failure+callback is tight when a merchant runs traffic spikes (sales, redirects). Once throttled, Cecabank's confirmation returns 429 and Cecabank retries — which is fine — but legitimate URL_OK landings from buyers behind the same NAT also share the budget.
- **Recommended fix**: Separate rate limiters: a per-IP limit on the browser endpoints, and either no limit or a much higher one on `/callback`. Use named limiters (`RateLimiter::for('cecabank-callback', …)`).
- **Regression test**: 100 callbacks in 60s do not return 429.

#### M-4: `error_message` column is `string` (255 chars), `raw_response` is stored verbatim
- **File**: `database/migrations/2024_01_01_000002_create_payment_transactions_table.php:30` and `src/Http/Controllers/PaymentController.php:98-103`
- **Description**: On invalid signature the package writes `error_message = 'Invalid response signature.'` (fine) but on the failure path stores `sanitizeResponse($request->all())` into `raw_response`. The allow-list (`ALLOWED_RESPONSE_FIELDS`) is correctly enforced — good — but a verbose error path or a future log statement that includes the **unfiltered** request could leak PAN-like fields if Cecabank ever forwards them. Defense-in-depth: shrinking the column and never logging the raw request.
- **Recommended fix**: Change `error_message` to `text` (so future operators don't truncate diagnostics) and add a unit test asserting that `sanitizeResponse` never returns a key outside the allow-list even when the input contains `Pan`, `Caducidad`, `CVV2`.
- **Regression test**: `sanitizeResponse(['Pan' => '4111…', 'Firma' => 'x'])` returns `['Firma' => 'x']` only.

#### M-5: `is_active` lacks a guard against multiple rows; no DB constraint on `provider`
- **File**: `database/migrations/2024_01_01_000001_create_payment_gateways_table.php:18,27,30`
- **Description**: `provider` is a free-text string defaulting to `cecabank`. Anything using `PaymentGateway::active()` returns the first active row regardless of provider, so if the host adds a second TPV provider as a row in the same table, the Cecabank service can pick the Stripe/Redsys row and crash.
- **Recommended fix**: Either scope `active()` to `provider = 'cecabank'`, or add a check constraint / index `(provider, is_active)` and update `active()`:
  ```php
  public static function active(): self
  {
      return self::where('provider', 'cecabank')->where('is_active', true)->sole();
  }
  ```
- **Regression test**: A row with `provider='redsys', is_active=true` is ignored by `PaymentGateway::active()`.

---

### LOW / INFORMATIONAL

#### L-1: `resources/views/redirect.blade.php` uses an inline auto-submit `<script>`
- **File**: `resources/views/redirect.blade.php:41-43`
- **Description**: HTML escaping is correct (`{{ }}` covers attributes), but the inline `<script>document.getElementById(…).submit()</script>` will be blocked by any host with `Content-Security-Policy: script-src 'self'`, leaving the user on a half-rendered page. Not a vulnerability — a deployment footgun.
- **Recommended fix**: Move the script to a small external asset published with `vendor:publish --tag=cecabank-views`, or use a `<noscript><form …><button>Continue</button></form></noscript>` fallback + a nonce-aware inline script:
  ```blade
  <script nonce="{{ csp_nonce() ?? '' }}">…</script>
  ```

#### L-2: `getEncryptionKeyAttribute()` is an accessor on a model that **also** exposes the raw columns
- **File**: `src/Models/PaymentGateway.php:53-65`
- **Description**: `$hidden` covers `encryption_key_test` and `encryption_key_prod`, so `toArray()` won't leak them. The virtual `encryption_key` accessor isn't in `$appends`, so it isn't serialized either. Good. But the columns remain fillable and any host that dumps a model via `dd($gateway->getAttributes())` (which bypasses `$hidden`) will print the decrypted keys. Document this and consider moving keys behind a dedicated method (e.g., `decryptedKey(string $env): string`) plus removing them from `$fillable` — they should be set via a deliberate `rotateKeys()` method.

#### L-3: Sandbox callback path returns `OKY` without verifying anything
- **File**: `src/Http/Controllers/PaymentController.php:89-92`
- **Description**: Comment correctly notes that sandbox transactions are reconciled by URL_OK only. But returning `$*$OKY$*$` for an arbitrary `operation_number` matching `is_sandbox = true` means a sandbox row gets a positive ack to Cecabank without verification. Low impact (sandbox is by definition test-only) but if a production deployment accidentally has `is_sandbox = true` rows mixed in, the host would never see the signature failure event. Add a log breadcrumb at minimum.

#### L-4: `composer.json` declares `illuminate/* ^11.0|^12.0|^13.0`
- **File**: `composer.json:9-12`
- **Description**: Laravel 13 doesn't exist yet (as of cutoff). The constraint is harmless because Composer resolves against what's installed, but it suggests the constraint set wasn't curated — and `php: ^8.2` is fine, while `phpunit ^11.0|^12.0` is broader than the boost guidelines (PHPUnit 11) would suggest. Informational. Tighten when 13 is released.

---

## Patches priority order

1. **C-1** — exclude `/payment/callback` from CSRF; ship a dedicated middleware stack (don't reuse `web`). Without this, real payments break.
2. **C-2** — wrap the callback state transition in `DB::transaction { lockForUpdate; … }` and only dispatch `PaymentCompleted` if the update actually transitioned the row.
3. **H-3** — drop `payable_type` / `payable_id` from `$fillable`; expose `attachPayable(Payable $p)`. Enforce morph map.
4. **H-1** — switch return tokens to a TTL'd signed envelope; reject tokens older than 30 min.
5. **H-2** — when token verification fails in `success()`, redirect to the **failure** fallback (or `abort(404)`), never to success.
6. **H-4** — validate `cecabank.urls.{test,production}` at provider boot (HTTPS, host ends with `.ceca.es`).
7. **M-1** — replace `time()` with `Str::random(12)` in `generateOperationNumber()` (and apply to sandbox).
8. **M-2 / M-5** — scope `PaymentGateway::active()` to `provider = 'cecabank'` and use `sole()` to fail loud on duplicates.
9. **M-3** — split rate limiters: per-IP for browser routes, per-route (or higher) for callback.
10. **M-4** — broaden `error_message` to `text`; add a regression test for `sanitizeResponse` allow-list completeness.
11. **L-1 / L-2 / L-3 / L-4** — defense-in-depth cleanup pass: CSP-friendly redirect view, dedicated key accessor method, sandbox-callback log breadcrumb, tighten composer constraints when Laravel 13 lands.
