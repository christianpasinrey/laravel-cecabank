<?php

namespace Cpr\Cecabank;

use Cpr\Cecabank\Contracts\Payable;
use Cpr\Cecabank\Models\PaymentGateway;
use Cpr\Cecabank\Models\PaymentTransaction;
use Cpr\Cecabank\Support\CheckoutPayload;
use Cpr\Cecabank\Support\SandboxPayload;
use Illuminate\Support\Str;

/**
 * Central API for the Cecabank integration.
 *
 * Frontend-agnostic by design: every method that produces a checkout form
 * returns a plain DTO ({@see CheckoutPayload}, {@see SandboxPayload}). It is
 * up to the host to render those fields with Blade, Inertia, JSON, etc.
 */
class CecabankService
{
    /**
     * Cecabank response fields we accept and persist. Anything else is dropped.
     */
    public const ALLOWED_RESPONSE_FIELDS = [
        'MerchantID',
        'AcquirerBIN',
        'TerminalID',
        'Num_operacion',
        'Importe',
        'TipoMoneda',
        'Exponente',
        'Idioma',
        'Pais',
        'Num_aut',
        'Referencia',
        'Firma',
        'Descripcion',
    ];

    // ---------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------

    /**
     * Generate a Cecabank form payload for the given payable and persist a
     * pending PaymentTransaction. Host code typically renders the returned
     * fields as a POST form pointing at `$payload->gatewayUrl`.
     */
    public function checkout(Payable $payable, ?PaymentGateway $gateway = null): CheckoutPayload
    {
        $gateway ??= PaymentGateway::active();

        $operationNumber = $this->generateOperationNumber($payable);
        $amountCents = $this->amountToCents($payable->paymentAmount());
        $token = $this->returnToken($operationNumber);

        $data = $this->buildFormData(
            $gateway,
            $operationNumber,
            $amountCents,
            route('cecabank.success', ['Num_operacion' => $operationNumber, 'token' => $token]),
            route('cecabank.failure', ['Num_operacion' => $operationNumber, 'token' => $token]),
        );

        if ($description = $payable->paymentDescription()) {
            $data['fields']['Descripcion'] = $description;
        }

        $transaction = PaymentTransaction::create([
            'payable_type' => $payable::class,
            'payable_id' => $payable->getKey(),
            'payment_gateway_id' => $gateway->id,
            'operation_number' => $operationNumber,
            'amount' => $payable->paymentAmount(),
            'status' => 'pending',
            'environment' => $gateway->environment ?: 'production',
            'signature_sent' => $data['signature'],
            'raw_request' => $data['fields'],
        ]);

        return new CheckoutPayload(
            fields: $data['fields'],
            gatewayUrl: $data['url'],
            operationNumber: $operationNumber,
            signature: $data['signature'],
            transaction: $transaction,
        );
    }

    /**
     * Generate a sandbox payment (no payable record). Host admin UIs call
     * this to drive a manual test against the gateway.
     */
    public function sandboxCheckout(
        PaymentGateway $gateway,
        float $amount,
        ?string $description = null,
        ?string $environment = null,
    ): SandboxPayload {
        $operationNumber = $this->generateSandboxOperationNumber();
        $amountCents = $this->amountToCents($amount);

        $data = $this->buildFormData(
            $gateway,
            $operationNumber,
            $amountCents,
            $this->sandboxReturnUrl('ok', $operationNumber),
            $this->sandboxReturnUrl('nok', $operationNumber),
            environment: $environment,
        );

        if ($description !== null && $description !== '') {
            $data['fields']['Descripcion'] = $description;
        }

        $preImage = $this->buildPreImage(
            $gateway, $operationNumber, $amountCents,
            $data['fields']['URL_OK'], $data['fields']['URL_NOK'],
            environment: $environment,
        );

        $preImageMasked = $this->buildPreImage(
            $gateway, $operationNumber, $amountCents,
            $data['fields']['URL_OK'], $data['fields']['URL_NOK'],
            environment: $environment, maskKey: true,
        );

        $transaction = PaymentTransaction::create([
            'payable_type' => null,
            'payable_id' => null,
            'payment_gateway_id' => $gateway->id,
            'operation_number' => $operationNumber,
            'amount' => $amount,
            'status' => 'pending',
            'environment' => $environment ?: 'production',
            'is_sandbox' => true,
            'signature_sent' => $data['signature'],
            'raw_request' => $data['fields'],
        ]);

        return new SandboxPayload(
            fields: $data['fields'],
            gatewayUrl: $data['url'],
            operationNumber: $operationNumber,
            signature: $data['signature'],
            preImage: $preImage,
            preImageMasked: $preImageMasked,
            environment: $environment ?: 'production',
            transaction: $transaction,
        );
    }

    /**
     * Compute the signature + URL for arbitrary sandbox params without
     * persisting anything. Useful for "what would be sent?" admin previews.
     *
     * @return array{operation_number: string, signature: string, pre_image_masked: string, fields: array<string, string>, url: string, environment: string}
     */
    public function previewSandboxSignature(
        PaymentGateway $gateway,
        float $amount,
        ?string $environment = null,
    ): array {
        $operationNumber = $this->generateSandboxOperationNumber();
        $amountCents = $this->amountToCents($amount);

        $data = $this->buildFormData(
            $gateway,
            $operationNumber,
            $amountCents,
            $this->sandboxReturnUrl('ok', $operationNumber),
            $this->sandboxReturnUrl('nok', $operationNumber),
            environment: $environment,
        );

        $preImageMasked = $this->buildPreImage(
            $gateway, $operationNumber, $amountCents,
            $data['fields']['URL_OK'], $data['fields']['URL_NOK'],
            environment: $environment, maskKey: true,
        );

        return [
            'operation_number' => $operationNumber,
            'signature' => $data['signature'],
            'pre_image_masked' => $preImageMasked,
            'fields' => $data['fields'],
            'url' => $data['url'],
            'environment' => $environment ?: 'production',
        ];
    }

    /**
     * Reconcile a sandbox URL_OK / URL_NOK return with the matching pending
     * transaction. Returns the updated transaction (or null if the operation
     * number was unknown / no longer pending).
     *
     * @param  array<string, mixed>  $params
     */
    public function reconcileSandboxReturn(string $operationNumber, array $params, bool $success): ?PaymentTransaction
    {
        $transaction = PaymentTransaction::where('operation_number', $operationNumber)
            ->where('is_sandbox', true)
            ->first();

        if (! $transaction || $transaction->status !== 'pending') {
            return $transaction;
        }

        $transaction->update([
            'status' => $success ? 'completed' : 'failed',
            'authorization_number' => $params['Num_aut'] ?? null,
            'reference' => $params['Referencia'] ?? null,
            'signature_response' => $params['Firma'] ?? null,
            'raw_response' => $params,
            'error_message' => $success ? null : (($params['Num_aut'] ?? null) ?: 'Payment rejected by URL_NOK.'),
            'completed_at' => $success ? now() : null,
        ]);

        return $transaction;
    }

    // ---------------------------------------------------------------------
    // Signature primitives
    // ---------------------------------------------------------------------

    public function calculateSignature(string $data): string
    {
        return strtolower(hash('sha256', $data));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function verifyCallbackSignature(array $params, PaymentGateway $gateway, ?string $environment = null): bool
    {
        $key = $environment !== null
            ? $gateway->getEncryptionKeyForEnvironment($environment)
            : $gateway->encryption_key;

        if (empty($key)) {
            return false;
        }

        $signatureData = $key
            .($params['MerchantID'] ?? '')
            .($params['AcquirerBIN'] ?? '')
            .($params['TerminalID'] ?? '')
            .($params['Num_operacion'] ?? '')
            .($params['Importe'] ?? '')
            .($params['TipoMoneda'] ?? '')
            .config('cecabank.exponent')
            .($params['Referencia'] ?? '');

        $expected = $this->calculateSignature($signatureData);

        return hash_equals($expected, strtolower((string) ($params['Firma'] ?? '')));
    }

    /**
     * Stable HMAC bound to an operation number, used to authenticate URL_OK /
     * URL_NOK returns (i.e. detect a tampered query string).
     */
    public function returnToken(string $operationNumber): string
    {
        return hash_hmac('sha256', $operationNumber, (string) config('app.key'));
    }

    public function verifyReturnToken(string $operationNumber, string $token): bool
    {
        if ($operationNumber === '' || $token === '') {
            return false;
        }

        return hash_equals($this->returnToken($operationNumber), $token);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, scalar>
     */
    public function sanitizeResponse(array $params): array
    {
        $clean = [];
        foreach (self::ALLOWED_RESPONSE_FIELDS as $field) {
            if (! array_key_exists($field, $params)) {
                continue;
            }
            $value = $params[$field];
            if (is_scalar($value)) {
                $clean[$field] = (string) $value;
            }
        }

        return $clean;
    }

    // ---------------------------------------------------------------------
    // Misc helpers (exposed because host code often needs them too)
    // ---------------------------------------------------------------------

    public function amountToCents(float $amount): string
    {
        return (string) round($amount * 100);
    }

    public function generateOperationNumber(Payable $payable): string
    {
        return 'OP'.$payable->getKey().'T'.time();
    }

    public function generateSandboxOperationNumber(): string
    {
        return 'SBX'.time().strtoupper(Str::random(4));
    }

    /**
     * Resolve the URL Cecabank should redirect to after a sandbox payment.
     * Hosts wire their own admin sandbox return route and point the package
     * at it via `config('cecabank.sandbox_return_routes.{ok,nok}')`.
     */
    private function sandboxReturnUrl(string $kind, string $operationNumber): string
    {
        $name = (string) config("cecabank.sandbox_return_routes.{$kind}", '');

        if ($name === '') {
            throw new \RuntimeException(
                "Cecabank sandbox checkout needs config('cecabank.sandbox_return_routes.{$kind}') ".
                'to be set to the route name of your host-owned sandbox handler.'
            );
        }

        return route($name, ['operationNumber' => $operationNumber]);
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @return array{fields: array<string, string>, url: string, operation_number: string, signature: string}
     */
    private function buildFormData(
        PaymentGateway $gateway,
        string $operationNumber,
        string $amountCents,
        string $urlOk,
        string $urlNok,
        string $exencionSca = '',
        ?string $environment = null,
    ): array {
        $exponent = config('cecabank.exponent');
        $cipher = config('cecabank.cipher');

        $signature = $this->calculateSignature(
            $this->buildPreImage($gateway, $operationNumber, $amountCents, $urlOk, $urlNok, $exencionSca, environment: $environment)
        );

        $fields = [
            'MerchantID' => $gateway->merchant_id,
            'AcquirerBIN' => $gateway->acquirer_bin,
            'TerminalID' => $gateway->terminal_id,
            'Num_operacion' => $operationNumber,
            'Importe' => $amountCents,
            'TipoMoneda' => $gateway->currency,
            'Exponente' => $exponent,
            'Cifrado' => $cipher,
            'URL_OK' => $urlOk,
            'URL_NOK' => $urlNok,
            'Firma' => $signature,
            'Pago_soportado' => config('cecabank.supported_payment'),
            'Idioma' => $gateway->language,
            'Exencion_SCA' => $exencionSca,
        ];

        return [
            'fields' => $fields,
            'url' => $environment !== null
                ? $gateway->getGatewayUrlForEnvironment($environment)
                : $gateway->getGatewayUrl(),
            'operation_number' => $operationNumber,
            'signature' => $signature,
        ];
    }

    private function buildPreImage(
        PaymentGateway $gateway,
        string $operationNumber,
        string $amountCents,
        string $urlOk,
        string $urlNok,
        string $exencionSca = '',
        bool $maskKey = false,
        ?string $environment = null,
    ): string {
        $key = $maskKey
            ? str_repeat('•', 8)
            : ($environment !== null
                ? $gateway->getEncryptionKeyForEnvironment($environment)
                : $gateway->encryption_key);

        return $key
            .$gateway->merchant_id
            .$gateway->acquirer_bin
            .$gateway->terminal_id
            .$operationNumber
            .$amountCents
            .$gateway->currency
            .config('cecabank.exponent')
            .config('cecabank.cipher')
            .$urlOk
            .$urlNok
            .$exencionSca;
    }
}
