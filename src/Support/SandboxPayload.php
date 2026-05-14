<?php

namespace Cpr\Cecabank\Support;

use Cpr\Cecabank\Models\PaymentTransaction;

/**
 * Result of {@see \Cpr\Cecabank\CecabankService::sandboxCheckout()}.
 *
 * Same shape as CheckoutPayload but enriched with the pre-image string (clear
 * + masked) so admin tooling can show signature debugging info.
 */
class SandboxPayload
{
    /**
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly string $gatewayUrl,
        public readonly string $operationNumber,
        public readonly string $signature,
        public readonly string $preImage,
        public readonly string $preImageMasked,
        public readonly string $environment,
        public readonly PaymentTransaction $transaction,
    ) {}

    /**
     * @return array{transaction_id: int, operation_number: string, signature: string, pre_image_masked: string, fields: array<string, string>, gateway_url: string, environment: string}
     */
    public function toArray(): array
    {
        return [
            'transaction_id' => (int) $this->transaction->getKey(),
            'operation_number' => $this->operationNumber,
            'signature' => $this->signature,
            'pre_image_masked' => $this->preImageMasked,
            'fields' => $this->fields,
            'gateway_url' => $this->gatewayUrl,
            'environment' => $this->environment,
        ];
    }
}
