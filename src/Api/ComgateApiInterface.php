<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin\Api;

use Comgate\SDK\Entity\Payment;
use Comgate\SDK\Entity\Response\PaymentCreateResponse;
use Comgate\SDK\Entity\Response\PaymentStatusResponse;

interface ComgateApiInterface
{
    /**
     * Whether this gateway configuration operates in Comgate's test mode.
     *
     * Comgate distinguishes test from live payments through the `test` payment
     * parameter rather than through a distinct API endpoint, so every payment
     * created through this API must be flagged consistently with the gateway
     * configuration it was built from.
     */
    public function isTest(): bool;

    public function createPayment(Payment $payment): PaymentCreateResponse;

    public function getStatus(string $transactionId): PaymentStatusResponse;
}
