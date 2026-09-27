<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin\Refund;

use Payum\Core\Registry\RegistryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Unicorncrew\SyliusComgatePlugin\Payum\ComgateGatewayFactory;
use Unicorncrew\SyliusComgatePlugin\Payum\Request\RefundPayment;
use Webmozart\Assert\Assert;

/**
 * Entry point for every admin-triggered refund (Sylius' own payment "Refund"
 * button and sylius/refund-plugin's refund payments): executes the refund through
 * the payment's own Payum gateway, so the (possibly encrypted) gateway configuration
 * is resolved exactly like it is for Capture/Notify.
 */
final class ComgatePaymentRefunder
{
    public function __construct(private readonly RegistryInterface $payum)
    {
    }

    /**
     * Whether the payment was paid through a Comgate gateway and has a Comgate
     * transaction that money can be returned from.
     */
    public function supports(PaymentInterface $payment): bool
    {
        $gatewayConfig = $payment->getMethod()?->getGatewayConfig();
        if (null === $gatewayConfig || ComgateGatewayFactory::FACTORY_NAME !== $gatewayConfig->getFactoryName()) {
            return false;
        }

        $transactionId = $payment->getDetails()['trans_id'] ?? null;

        return \is_string($transactionId) && '' !== $transactionId;
    }

    /** @param int $amount in the payment currency's minor units */
    public function refund(PaymentInterface $payment, int $amount): void
    {
        Assert::true($this->supports($payment), 'The payment cannot be refunded through Comgate.');

        $gatewayName = $payment->getMethod()?->getGatewayConfig()?->getGatewayName();
        Assert::stringNotEmpty($gatewayName);

        $this->payum->getGateway($gatewayName)->execute(new RefundPayment($payment, $amount));
    }
}
