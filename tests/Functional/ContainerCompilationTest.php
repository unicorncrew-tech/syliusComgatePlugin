<?php

declare(strict_types=1);

namespace Tests\Unicorncrew\SyliusComgatePlugin\Functional;

use Comgate\SDK\Entity\Refund;
use Comgate\SDK\Entity\Response\RefundResponse;
use Comgate\SDK\Http\Response;
use Nyholm\Psr7\Response as Psr7Response;
use Payum\Bundle\PayumBundle\Payum\Payum;
use Payum\Core\GatewayInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Unicorncrew\SyliusComgatePlugin\Api\ComgateApiInterface;
use Unicorncrew\SyliusComgatePlugin\Form\Type\ComgateGatewayConfigurationType;
use Unicorncrew\SyliusComgatePlugin\Payum\Action\CaptureAction;
use Unicorncrew\SyliusComgatePlugin\Payum\Action\ConvertPaymentAction;
use Unicorncrew\SyliusComgatePlugin\Payum\Action\NotifyAction;
use Unicorncrew\SyliusComgatePlugin\Payum\Action\RefundAction;
use Unicorncrew\SyliusComgatePlugin\Payum\Action\StatusAction;
use Unicorncrew\SyliusComgatePlugin\Payum\Request\RefundPayment;

/**
 * Boots the real Sylius kernel (via sylius/test-application) with this plugin enabled and proves the
 * YAML service wiring actually compiles: the "comgate" Payum gateway factory is registered, its actions
 * resolve, and the admin gateway configuration form type is registered - exactly what `debug:container`
 * would otherwise be used for, without requiring a database connection.
 */
final class ContainerCompilationTest extends KernelTestCase
{
    public function testThePluginBundleIsRegistered(): void
    {
        self::bootKernel();

        $bundles = self::getContainer()->getParameter('kernel.bundles');

        self::assertArrayHasKey('UnicorncrewSyliusComgatePlugin', $bundles);
    }

    public function testTheComgatePayumGatewayFactoryIsRegistered(): void
    {
        self::bootKernel();

        /** @var Payum $payum */
        $payum = self::getContainer()->get('payum');

        self::assertArrayHasKey('comgate', $payum->getGatewayFactories());

        $gateway = $payum->getGatewayFactory('comgate')->create([
            'merchant' => '123456',
            'secret' => 'foobarbaz',
        ]);

        self::assertInstanceOf(GatewayInterface::class, $gateway);
    }

    public function testTheComgateGatewayExecutesRefunds(): void
    {
        self::bootKernel();

        $api = $this->createMock(ComgateApiInterface::class);
        $api->expects(self::once())
            ->method('refundPayment')
            ->with(self::callback(fn (Refund $refund): bool => 'XXXX-YYYY-ZZZZ' === $refund->getTransId() && 4500 === $refund->getAmount()->get()))
            ->willReturn(new RefundResponse(new Response(new Psr7Response(200, [], '{"code":0,"message":"OK"}'))))
        ;

        /** @var Payum $payum */
        $payum = self::getContainer()->get('payum');
        $gateway = $payum->getGatewayFactory('comgate')->create(['payum.api' => $api]);

        // An interface mock rather than the Payment entity: Payum's Doctrine storage extension
        // would otherwise persist + flush the entity after the request, which needs a database.
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getCurrencyCode')->willReturn('CZK');
        $payment->method('getDetails')->willReturn(['trans_id' => 'XXXX-YYYY-ZZZZ']);

        $gateway->execute(new RefundPayment($payment, 4500));
    }

    public function testTheGatewayActionsAndApiServicesAreWired(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(ConvertPaymentAction::class, $container->get('unicorncrew_sylius_comgate.payum.action.convert_payment'));
        self::assertInstanceOf(StatusAction::class, $container->get('unicorncrew_sylius_comgate.payum.action.status'));
        self::assertInstanceOf(CaptureAction::class, $container->get('unicorncrew_sylius_comgate.payum.action.capture'));
        self::assertInstanceOf(NotifyAction::class, $container->get('unicorncrew_sylius_comgate.payum.action.notify'));
        self::assertInstanceOf(RefundAction::class, $container->get('unicorncrew_sylius_comgate.payum.action.refund'));
    }

    public function testTheAdminGatewayConfigurationFormTypeIsRegistered(): void
    {
        self::bootKernel();

        self::assertTrue(self::getContainer()->has(ComgateGatewayConfigurationType::class));
    }

    public function testTheGatewayFactoriesParameterExposesComgate(): void
    {
        self::bootKernel();

        $gatewayFactories = self::getContainer()->getParameter('sylius.gateway_factories');

        self::assertArrayHasKey('comgate', $gatewayFactories);
    }
}
