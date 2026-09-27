<?php

declare(strict_types=1);

namespace Tests\Unicorncrew\SyliusComgatePlugin\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Unicorncrew\SyliusComgatePlugin\DependencyInjection\Compiler\RegisterRefundPluginGatewayPass;

final class RegisterRefundPluginGatewayPassTest extends TestCase
{
    public function testItOffersComgateAsARefundGatewayNextToTheAlreadySupportedOnes(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('sylius_refund.supported_gateways', ['offline', 'mollie']);

        (new RegisterRefundPluginGatewayPass())->process($container);
        (new RegisterRefundPluginGatewayPass())->process($container);

        self::assertSame(['offline', 'mollie', 'comgate'], $container->getParameter('sylius_refund.supported_gateways'));
    }

    public function testItDoesNothingWithoutTheRefundPlugin(): void
    {
        $container = new ContainerBuilder();

        (new RegisterRefundPluginGatewayPass())->process($container);

        self::assertFalse($container->hasParameter('sylius_refund.supported_gateways'));
    }
}
