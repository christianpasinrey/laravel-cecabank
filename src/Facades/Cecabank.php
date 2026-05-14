<?php

namespace Cpr\Cecabank\Facades;

use Cpr\Cecabank\CecabankService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Cpr\Cecabank\Support\CheckoutPayload checkout(\Cpr\Cecabank\Contracts\Payable $payable, ?\Cpr\Cecabank\Models\PaymentGateway $gateway = null)
 * @method static \Cpr\Cecabank\Support\SandboxPayload sandboxCheckout(\Cpr\Cecabank\Models\PaymentGateway $gateway, float $amount, ?string $description = null, ?string $environment = null)
 * @method static array previewSandboxSignature(\Cpr\Cecabank\Models\PaymentGateway $gateway, float $amount, ?string $environment = null)
 * @method static \Cpr\Cecabank\Models\PaymentTransaction|null reconcileSandboxReturn(string $operationNumber, array $params, bool $success)
 * @method static string calculateSignature(string $data)
 * @method static bool verifyCallbackSignature(array $params, \Cpr\Cecabank\Models\PaymentGateway $gateway, ?string $environment = null)
 * @method static string returnToken(string $operationNumber)
 * @method static bool verifyReturnToken(string $operationNumber, string $token)
 * @method static array sanitizeResponse(array $params)
 * @method static string amountToCents(float $amount)
 * @method static string generateSandboxOperationNumber()
 */
class Cecabank extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CecabankService::class;
    }
}
