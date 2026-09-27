<?php

declare(strict_types=1);

namespace Tests\Unicorncrew\SyliusComgatePlugin\Functional;

use Payum\Core\GatewayInterface;
use Payum\Core\Registry\RegistryInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\RefundPlugin\Entity\RefundPaymentInterface;
use Sylius\RefundPlugin\Event\RefundPaymentGenerated;
use Sylius\RefundPlugin\Provider\RefundPaymentMethodsProviderInterface;
use Sylius\RefundPlugin\StateResolver\RefundPaymentCompletedStateApplierInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Unicorncrew\SyliusComgatePlugin\Payum\Request\RefundPayment;

/**
 * Boots the Sylius kernel with sylius/refund-plugin enabled (own bundle list and cache directory,
 * so the other functional tests keep running without it) and exercises the integration through
 * the refund plugin's own services, with only repositories, Payum and the state applier stubbed.
 */
final class RefundPluginIntegrationTest extends KernelTestCase
{
    /** @var array<string, string|null> */
    private array $originalServer = [];

    protected function setUp(): void
    {
        $overrides = [
            'TEST_APP_BUNDLES_PATH' => 'tests/TestApplication/refund_plugin/bundles.php',
            'CONFIGS_TO_IMPORT' => '@UnicorncrewSyliusComgatePlugin/config/config.yaml;@SyliusRefundPlugin/config/config.yaml',
            'APP_CACHE_DIR' => \dirname(__DIR__, 2) . '/var/cache/refund_plugin',
        ];

        foreach ($overrides as $name => $value) {
            $this->originalServer[$name] = $_SERVER[$name] ?? null;
            $_SERVER[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->originalServer as $name => $value) {
            if (null === $value) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $value;
            }
        }
    }

    public function testComgatePaymentMethodsAreOfferedAsRefundMethods(): void
    {
        self::bootKernel();

        $channel = new Channel();
        $order = new Order();
        $order->setChannel($channel);

        $comgate = $this->createPaymentMethod('comgate');
        $offline = $this->createPaymentMethod('offline');
        $other = $this->createPaymentMethod('stripe_checkout');

        $paymentMethodRepository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $paymentMethodRepository->method('findEnabledForChannel')->with($channel)->willReturn([$comgate, $offline, $other]);
        self::getContainer()->set('sylius.repository.payment_method', $paymentMethodRepository);

        /** @var RefundPaymentMethodsProviderInterface $provider */
        $provider = self::getContainer()->get('sylius_refund.provider.refund_payment_methods');

        self::assertSame([$comgate, $offline], $provider->findForOrder($order));
    }

    public function testAGeneratedComgateRefundPaymentIsRefundedAtComgateAndCompleted(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $payment = new Payment();
        $payment->setMethod($this->createPaymentMethod('comgate'));
        $payment->setCurrencyCode('CZK');
        $payment->setDetails(['trans_id' => 'XXXX-YYYY-ZZZZ']);

        $paymentMethodRepository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $paymentMethodRepository->method('find')->with(3)->willReturn($this->createPaymentMethod('comgate'));
        $container->set('sylius.repository.payment_method', $paymentMethodRepository);

        $paymentRepository = $this->createMock(PaymentRepositoryInterface::class);
        $paymentRepository->method('find')->with(42)->willReturn($payment);
        $container->set('sylius.repository.payment', $paymentRepository);

        $refundPayment = $this->createMock(RefundPaymentInterface::class);
        $refundPaymentRepository = $this->createMock(RepositoryInterface::class);
        $refundPaymentRepository->method('find')->with(7)->willReturn($refundPayment);
        $container->set('sylius_refund.repository.refund_payment', $refundPaymentRepository);

        $applier = $this->createMock(RefundPaymentCompletedStateApplierInterface::class);
        $applier->expects(self::once())->method('apply')->with($refundPayment);
        $container->set('sylius_refund.state_resolver.refund_payment_completed_applier', $applier);

        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->expects(self::once())
            ->method('execute')
            ->with(self::callback(fn (RefundPayment $request): bool => $request->getModel() === $payment && 4500 === $request->getAmount()))
        ;
        $payum = $this->createMock(RegistryInterface::class);
        $payum->method('getGateway')->with('comgate_cz')->willReturn($gateway);
        $container->set('payum', $payum);

        /** @var MessageBusInterface $eventBus */
        $eventBus = $container->get('sylius.event_bus');
        $eventBus->dispatch(new RefundPaymentGenerated(7, '000000042', 4500, 'CZK', 3, 42));
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
