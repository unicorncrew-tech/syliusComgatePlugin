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
use Sylius\Resource\Exception\UpdateHandlingException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Marking;
use Unicorncrew\SyliusComgatePlugin\Payum\Request\RefundPayment;
use Unicorncrew\SyliusComgatePlugin\Refund\ComgatePaymentRefunder;
use Unicorncrew\SyliusComgatePlugin\Refund\RefundPaymentListener;

final class RefundPaymentListenerTest extends TestCase
{
    public function testItRefundsTheWholePaymentThroughItsComgateGateway(): void
    {
        $payment = $this->createPayment('comgate', ['trans_id' => 'XXXX-YYYY-ZZZZ']);

        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->expects(self::once())
            ->method('execute')
            ->with(self::callback(function (RefundPayment $request) use ($payment): bool {
                self::assertSame($payment, $request->getModel());
                self::assertSame(12345, $request->getAmount());

                return true;
            }))
        ;

        $payum = $this->createMock(RegistryInterface::class);
        $payum->expects(self::once())->method('getGateway')->with('comgate_cz')->willReturn($gateway);

        $listener = new RefundPaymentListener(new ComgatePaymentRefunder($payum), new RequestStack());
        $listener(new TransitionEvent($payment, new Marking()));
    }

    public function testItLeavesPaymentsOfOtherGatewaysToTheStateChange(): void
    {
        $payum = $this->createMock(RegistryInterface::class);
        $payum->expects(self::never())->method('getGateway');

        $listener = new RefundPaymentListener(new ComgatePaymentRefunder($payum), new RequestStack());
        $listener->refund($this->createPayment('offline', ['trans_id' => 'XXXX-YYYY-ZZZZ']));
    }

    public function testItLeavesComgatePaymentsWithoutAComgateTransactionToTheStateChange(): void
    {
        $payum = $this->createMock(RegistryInterface::class);
        $payum->expects(self::never())->method('getGateway');

        $listener = new RefundPaymentListener(new ComgatePaymentRefunder($payum), new RequestStack());
        $listener->refund($this->createPayment('comgate', []));
    }

    public function testItAbortsTheTransitionAndTellsTheAdminWhyWhenComgateRefusesTheRefund(): void
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willThrowException(new ApiException('Refund amount exceeds the payment.', 1400));

        $payum = $this->createMock(RegistryInterface::class);
        $payum->method('getGateway')->willReturn($gateway);

        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $listener = new RefundPaymentListener(new ComgatePaymentRefunder($payum), $requestStack);

        try {
            $listener->refund($this->createPayment('comgate', ['trans_id' => 'XXXX-YYYY-ZZZZ']));
            self::fail('Expected the refund transition to be aborted.');
        } catch (UpdateHandlingException $exception) {
            self::assertSame('Refund amount exceeds the payment.', $exception->getMessage());
        }

        self::assertSame([[
            'message' => 'unicorncrew_sylius_comgate.refund_failed',
            'parameters' => ['%reason%' => 'Refund amount exceeds the payment.'],
        ]], $session->getFlashBag()->get('error'));
    }

    /** @param array<string, mixed> $details */
    private function createPayment(string $factoryName, array $details): Payment
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName($factoryName);
        $gatewayConfig->setGatewayName('comgate_cz');

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setGatewayConfig($gatewayConfig);

        $payment = new Payment();
        $payment->setMethod($paymentMethod);
        $payment->setAmount(12345);
        $payment->setCurrencyCode('CZK');
        $payment->setDetails($details);

        return $payment;
    }
}
