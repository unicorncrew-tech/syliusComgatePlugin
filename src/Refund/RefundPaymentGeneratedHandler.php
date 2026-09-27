<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin\Refund;

use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\RefundPlugin\Entity\RefundPaymentInterface;
use Sylius\RefundPlugin\Event\RefundPaymentGenerated;
use Sylius\RefundPlugin\StateResolver\RefundPaymentCompletedStateApplierInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Unicorncrew\SyliusComgatePlugin\Payum\ComgateGatewayFactory;
use Webmozart\Assert\Assert;

/**
 * sylius/refund-plugin integration: when an admin refunds order units choosing a
 * Comgate payment method as the refund method, return that amount through the
 * order's Comgate payment and mark the refund payment completed.
 *
 * Only registered when SyliusRefundPlugin is enabled. The event is dispatched
 * synchronously inside the refund plugin's `RefundUnits` command transaction, so
 * throwing here (Comgate refused the refund, or the order was not paid through
 * Comgate) rolls the whole refund back instead of recording money that never moved.
 */
final class RefundPaymentGeneratedHandler
{
    /**
     * @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository
     * @param PaymentRepositoryInterface<PaymentInterface> $paymentRepository
     * @param RepositoryInterface<RefundPaymentInterface> $refundPaymentRepository
     */
    public function __construct(
        private readonly ComgatePaymentRefunder $refunder,
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly RepositoryInterface $refundPaymentRepository,
        private readonly RefundPaymentCompletedStateApplierInterface $refundPaymentCompletedStateApplier,
    ) {
    }

    public function __invoke(RefundPaymentGenerated $event): void
    {
        $refundPaymentMethod = $this->paymentMethodRepository->find($event->paymentMethodId());
        if (
            !$refundPaymentMethod instanceof PaymentMethodInterface ||
            ComgateGatewayFactory::FACTORY_NAME !== $refundPaymentMethod->getGatewayConfig()?->getFactoryName()
        ) {
            return;
        }

        $payment = $this->paymentRepository->find($event->paymentId());
        Assert::isInstanceOf($payment, PaymentInterface::class);
        Assert::true($this->refunder->supports($payment), \sprintf(
            'Order "%s" was not paid through Comgate, so it cannot be refunded through Comgate.',
            $event->orderNumber(),
        ));

        $this->refunder->refund($payment, $event->amount());

        $refundPayment = $this->refundPaymentRepository->find($event->id());
        Assert::isInstanceOf($refundPayment, RefundPaymentInterface::class);

        $this->refundPaymentCompletedStateApplier->apply($refundPayment);
    }
}
