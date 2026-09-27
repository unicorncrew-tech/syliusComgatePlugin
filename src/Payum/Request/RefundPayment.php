<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin\Payum\Request;

use Payum\Core\Request\Refund;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Payum's generic {@see Refund} request carries no amount; Comgate supports
 * partial refunds, so the amount (in the payment's minor units) travels with it.
 */
final class RefundPayment extends Refund
{
    public function __construct(PaymentInterface $payment, private readonly int $amount)
    {
        parent::__construct($payment);
    }

    public function getAmount(): int
    {
        return $this->amount;
    }
}
