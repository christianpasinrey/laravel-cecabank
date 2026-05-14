<?php

namespace Cpr\Cecabank\Contracts;

/**
 * Implemented by the host application model that represents whatever is being
 * paid (Order, Invoice, Booking…). Cecabank only needs to know the amount,
 * a reference and where to land the user after the gateway redirects back.
 */
interface Payable
{
    public function getKey();

    /**
     * Amount to charge, in the gateway's currency (euros, not cents).
     */
    public function paymentAmount(): float;

    /**
     * Stable, human-readable identifier (e.g. an order number).
     */
    public function paymentReference(): string;

    /**
     * Optional description sent in the Cecabank `Descripcion` field.
     */
    public function paymentDescription(): ?string;

    /**
     * Whether this record can currently start a new payment attempt.
     */
    public function isPayable(): bool;

    /**
     * Route name the user is redirected to after a successful payment.
     */
    public function paymentSuccessRoute(): string;

    /**
     * Route name the user is redirected to after a failed/canceled payment.
     */
    public function paymentFailureRoute(): string;
}
