<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin\Api;

use Comgate\SDK\Entity\Payment;
use Comgate\SDK\Entity\Refund;
use Comgate\SDK\Entity\Response\PaymentCreateResponse;
use Comgate\SDK\Entity\Response\PaymentStatusResponse;
use Comgate\SDK\Entity\Response\RefundResponse;

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

    /**
     * Refunds (fully or partially) an already paid Comgate transaction.
     *
     * Comgate refunds in the currency of the original transaction; the
     * refunded amount is validated server-side against what is left to refund.
     */
    public function refundPayment(Refund $refund): RefundResponse;
}
