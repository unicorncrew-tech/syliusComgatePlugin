<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Unicorncrew\SyliusComgatePlugin\Payum\ComgateGatewayFactory;

/**
 * Makes Comgate payment methods selectable as the refund method in sylius/refund-plugin,
 * which only offers methods whose gateway factory is listed in `sylius_refund.supported_gateways`
 * (`offline` only, by default). Appended rather than redefined, so the gateways listed by the
 * application or by other plugins are kept.
 */
final class RegisterRefundPluginGatewayPass implements CompilerPassInterface
{
    private const PARAMETER = 'sylius_refund.supported_gateways';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::PARAMETER)) {
            return;
        }

        /** @var list<string> $gateways */
        $gateways = $container->getParameter(self::PARAMETER);
        if (\in_array(ComgateGatewayFactory::FACTORY_NAME, $gateways, true)) {
            return;
        }

        $gateways[] = ComgateGatewayFactory::FACTORY_NAME;
        $container->setParameter(self::PARAMETER, $gateways);
    }
}
