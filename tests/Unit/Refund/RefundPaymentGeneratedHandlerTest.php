<?php

declare(strict_types=1);

namespace Tests\Unicorncrew\SyliusComgatePlugin\Unit\Refund;

use Comgate\SDK\Exception\ApiException;
use Payum\Core\GatewayInterface;
use Payum\Core\Registry\RegistryInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\RefundPlugin\Entity\RefundPaymentInterface;
use Sylius\RefundPlugin\Event\RefundPaymentGenerated;
use Sylius\RefundPlugin\StateResolver\RefundPaymentCompletedStateApplierInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Unicorncrew\SyliusComgatePlugin\Payum\Request\RefundPayment;
use Unicorncrew\SyliusComgatePlugin\Refund\ComgatePaymentRefunder;
use Unicorncrew\SyliusComgatePlugin\Refund\RefundPaymentGeneratedHandler;

final class RefundPaymentGeneratedHandlerTest extends TestCase
{
    private const REFUND_PAYMENT_ID = 7;

    private const REFUND_METHOD_ID = 3;

    private const PAYMENT_ID = 42;

    public function testItRefundsTheAmountThroughTheOrdersComgatePaymentAndCompletesTheRefundPayment(): void
    {
        $payment = $this->createPayment('comgate', ['trans_id' => 'XXXX-YYYY-ZZZZ']);
        $refundPayment = $this->createMock(RefundPaymentInterface::class);

        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->expects(self::once())
            ->method('execute')
            ->with(self::callback(function (RefundPayment $request) use ($payment): bool {
                self::assertSame($payment, $request->getModel());
                self::assertSame(4500, $request->getAmount());

                return true;
            }))
        ;

        $applier = $this->createMock(RefundPaymentCompletedStateApplierInterface::class);
        $applier->expects(self::once())->method('apply')->with($refundPayment);

        $handler = $this->createHandler(
            refundMethodFactoryName: 'comgate',
            payment: $payment,
            refundPayment: $refundPayment,
            gateway: $gateway,
            applier: $applier,
        );

        $handler($this->createEvent(amount: 4500));
    }

    public function testItIgnoresRefundsToANonComgateRefundMethod(): void
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->expects(self::never())->method('execute');

        $applier = $this->createMock(RefundPaymentCompletedStateApplierInterface::class);
        $applier->expects(self::never())->method('apply');

        $handler = $this->createHandler(
            refundMethodFactoryName: 'offline',
            payment: $this->createPayment('comgate', ['trans_id' => 'XXXX-YYYY-ZZZZ']),
            refundPayment: $this->createMock(RefundPaymentInterface::class),
            gateway: $gateway,
            applier: $applier,
        );

        $handler($this->createEvent(amount: 4500));
    }

    public function testItRefusesAComgateRefundOfAnOrderNotPaidThroughComgate(): void
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->expects(self::never())->method('execute');

        $applier = $this->createMock(RefundPaymentCompletedStateApplierInterface::class);
        $applier->expects(self::never())->method('apply');

        $handler = $this->createHandler(
            refundMethodFactoryName: 'comgate',
            payment: $this->createPayment('offline', []),
            refundPayment: $this->createMock(RefundPaymentInterface::class),
            gateway: $gateway,
            applier: $applier,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Order "000000042" was not paid through Comgate');

        $handler($this->createEvent(amount: 4500));
    }

    public function testItLeavesTheRefundPaymentOpenWhenComgateRefusesTheRefund(): void
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willThrowException(new ApiException('Refund amount exceeds the payment.', 1400));

        $applier = $this->createMock(RefundPaymentCompletedStateApplierInterface::class);
        $applier->expects(self::never())->method('apply');

        $handler = $this->createHandler(
            refundMethodFactoryName: 'comgate',
            payment: $this->createPayment('comgate', ['trans_id' => 'XXXX-YYYY-ZZZZ']),
            refundPayment: $this->createMock(RefundPaymentInterface::class),
            gateway: $gateway,
            applier: $applier,
        );

        $this->expectException(ApiException::class);

        $handler($this->createEvent(amount: 4500));
    }

    private function createHandler(
        string $refundMethodFactoryName,
        Payment $payment,
        RefundPaymentInterface $refundPayment,
        GatewayInterface $gateway,
        RefundPaymentCompletedStateApplierInterface $applier,
    ): RefundPaymentGeneratedHandler {
        $payum = $this->createMock(RegistryInterface::class);
        $payum->method('getGateway')->with('comgate_cz')->willReturn($gateway);

        $paymentMethodRepository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $paymentMethodRepository->method('find')->with(self::REFUND_METHOD_ID)->willReturn($this->createPaymentMethod($refundMethodFactoryName));

        $paymentRepository = $this->createMock(PaymentRepositoryInterface::class);
        $paymentRepository->method('find')->with(self::PAYMENT_ID)->willReturn($payment);

        $refundPaymentRepository = $this->createMock(RepositoryInterface::class);
        $refundPaymentRepository->method('find')->with(self::REFUND_PAYMENT_ID)->willReturn($refundPayment);

        return new RefundPaymentGeneratedHandler(
            new ComgatePaymentRefunder($payum),
            $paymentMethodRepository,
            $paymentRepository,
            $refundPaymentRepository,
            $applier,
        );
    }

    private function createEvent(int $amount): RefundPaymentGenerated
    {
        return new RefundPaymentGenerated(
            self::REFUND_PAYMENT_ID,
            '000000042',
            $amount,
            'CZK',
            self::REFUND_METHOD_ID,
            self::PAYMENT_ID,
        );
    }

    /** @param array<string, mixed> $details */
    private function createPayment(string $factoryName, array $details): Payment
    {
        $payment = new Payment();
        $payment->setMethod($this->createPaymentMethod($factoryName));
        $payment->setAmount(12345);
        $payment->setCurrencyCode('CZK');
        $payment->setDetails($details);

        return $payment;
    }

    private function createPaymentMethod(string $factoryName): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factoryName);
        $gatewayConfig->setGatewayName('comgate_cz');

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setGatewayConfig($gatewayConfig);

        return $paymentMethod;
    }
}
