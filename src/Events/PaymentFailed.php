<?php

namespace Cpr\Cecabank\Events;

use Cpr\Cecabank\Contracts\Payable;
use Cpr\Cecabank\Models\PaymentTransaction;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentFailed
{
    use Dispatchable;

    public function __construct(
        public readonly PaymentTransaction $transaction,
        public readonly ?Payable $payable,
        public readonly ?string $reason = null,
    ) {}
}
