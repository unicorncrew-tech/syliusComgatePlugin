<?php

declare(strict_types=1);

namespace Tests\Unicorncrew\SyliusComgatePlugin\Functional;

use Comgate\SDK\Exception\ApiException;
use Payum\Core\GatewayInterface;
use Payum\Core\Registry\RegistryInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Resource\Exception\UpdateHandlingException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Unicorncrew\SyliusComgatePlugin\Payum\Request\RefundPayment;

/**
 * Applies Sylius' own `sylius_payment` refund transition (what the admin payment "Refund"
 * button does) through the real state machine, with only the Payum registry stubbed so no
 * database or Comgate call is needed.
 */
final class PaymentRefundTransitionTest extends KernelTestCase
{
    public function testRefundingAComgatePaymentReturnsTheMoneyAtComgate(): void
    {
        self::bootKernel();

        $payment = $this->createCompletedComgatePayment();

        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->expects(self::once())
            ->method('execute')
            ->with(self::callback(fn (RefundPayment $request): bool => $request->getModel() === $payment && 12345 === $request->getAmount()))
        ;
        $this->stubPayumGateway('comgate_cz', $gateway);

        $this->getStateMachine()->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);

        self::assertSame(PaymentInterface::STATE_REFUNDED, $payment->getState());
    }

    public function testAPaymentComgateRefusesToRefundIsNotMarkedAsRefunded(): void
    {
        self::bootKernel();

        $payment = $this->createCompletedComgatePayment();

        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willThrowException(new ApiException('Refund amount exceeds the payment.', 1400));
        $this->stubPayumGateway('comgate_cz', $gateway);

        try {
            $this->getStateMachine()->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_REFUND);
            self::fail('Expected the refund transition to be aborted.');
        } catch (UpdateHandlingException) {
        }

        self::assertSame(PaymentInterface::STATE_COMPLETED, $payment->getState());
    }

    private function createCompletedComgatePayment(): Payment
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName('comgate');
        $gatewayConfig->setGatewayName('comgate_cz');

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setGatewayConfig($gatewayConfig);

        $order = new Order();
        $order->setPaymentState(OrderPaymentStates::STATE_PAID);

        $payment = new Payment();
        $payment->setMethod($paymentMethod);
        $payment->setAmount(12345);
        $payment->setCurrencyCode('CZK');
        $payment->setState(PaymentInterface::STATE_COMPLETED);
        $payment->setDetails(['trans_id' => 'XXXX-YYYY-ZZZZ', 'status' => 'PAID']);
        $order->addPayment($payment);

        return $payment;
    }

    private function stubPayumGateway(string $gatewayName, GatewayInterface $gateway): void
    {
        $payum = $this->createMock(RegistryInterface::class);
        $payum->method('getGateway')->with($gatewayName)->willReturn($gateway);

        self::getContainer()->set('payum', $payum);
    }

    private function getStateMachine(): StateMachineInterface
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');

        return $stateMachine;
    }
}
