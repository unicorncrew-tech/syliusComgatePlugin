<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin\Payum\Action;

use Comgate\SDK\Entity\Money;
use Comgate\SDK\Entity\Refund;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Sylius\Component\Core\Model\PaymentInterface;
use Unicorncrew\SyliusComgatePlugin\Api\ComgateApiInterface;
use Unicorncrew\SyliusComgatePlugin\Payum\Request\RefundPayment;

/**
 * Refunds (part of) a paid payment at Comgate. Any Comgate error (e.g. the amount
 * exceeding what is left to refund) propagates as the SDK's exception, so callers
 * can abort whatever state change the refund was meant to back.
 */
final class RefundAction implements ActionInterface, ApiAwareInterface
{
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = ComgateApiInterface::class;
    }

    /** @param RefundPayment $request */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();

        $transactionId = $payment->getDetails()['trans_id'] ?? null;
        if (!\is_string($transactionId) || '' === $transactionId) {
            throw new LogicException('RefundAction requires a payment that has been created at Comgate (missing "trans_id").');
        }

        $amount = $request->getAmount();
        if ($amount <= 0) {
            throw new LogicException(\sprintf('RefundAction requires a positive amount, %d given.', $amount));
        }

        $refund = new Refund();
        $refund
            ->setTransId($transactionId)
            ->setAmount(Money::ofCents($amount))
            ->setCurrency((string) $payment->getCurrencyCode())
            ->setTest($this->api->isTest())
        ;

        $this->api->refundPayment($refund);
    }

    public function supports($request): bool
    {
        return
            $request instanceof RefundPayment &&
            $request->getModel() instanceof PaymentInterface
        ;
    }
}
