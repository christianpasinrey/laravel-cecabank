<?php

namespace Cpr\Cecabank\Support;

use Cpr\Cecabank\Models\PaymentTransaction;

/**
 * Result of {@see \Cpr\Cecabank\CecabankService::checkout()}.
 *
 * Hand these fields to your frontend layer (Inertia props, Blade variables,
 * JSON response…) so it can render a POST form pointing at `gatewayUrl` with
 * the hidden `fields` and auto-submit it.
 */
class CheckoutPayload
{
    /**
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly string $gatewayUrl,
        public readonly string $operationNumber,
        public readonly string $signature,
        public readonly PaymentTransaction $transaction,
    ) {}

    /**
     * @return array{fields: array<string, string>, gateway_url: string, operation_number: string, signature: string, transaction_id: int}
     */
    public function toArray(): array
    {
        return [
            'fields' => $this->fields,
            'gateway_url' => $this->gatewayUrl,
            'operation_number' => $this->operationNumber,
            'signature' => $this->signature,
            'transaction_id' => (int) $this->transaction->getKey(),
        ];
    }
}
