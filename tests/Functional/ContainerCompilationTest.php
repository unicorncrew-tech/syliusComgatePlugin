<?php

declare(strict_types=1);

namespace Tests\Unicorncrew\SyliusComgatePlugin\Functional;

use Payum\Bundle\PayumBundle\Payum\Payum;
use Payum\Core\GatewayInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Unicorncrew\SyliusComgatePlugin\Form\Type\ComgateGatewayConfigurationType;
use Unicorncrew\SyliusComgatePlugin\Payum\Action\CaptureAction;
use Unicorncrew\SyliusComgatePlugin\Payum\Action\ConvertPaymentAction;
use Unicorncrew\SyliusComgatePlugin\Payum\Action\NotifyAction;
use Unicorncrew\SyliusComgatePlugin\Payum\Action\StatusAction;

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

    public function testTheGatewayActionsAndApiServicesAreWired(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(ConvertPaymentAction::class, $container->get('unicorncrew_sylius_comgate.payum.action.convert_payment'));
        self::assertInstanceOf(StatusAction::class, $container->get('unicorncrew_sylius_comgate.payum.action.status'));
        self::assertInstanceOf(CaptureAction::class, $container->get('unicorncrew_sylius_comgate.payum.action.capture'));
        self::assertInstanceOf(NotifyAction::class, $container->get('unicorncrew_sylius_comgate.payum.action.notify'));
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
