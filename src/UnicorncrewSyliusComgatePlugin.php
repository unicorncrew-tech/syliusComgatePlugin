<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin;

use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Unicorncrew\SyliusComgatePlugin\DependencyInjection\Compiler\RegisterRefundPluginGatewayPass;

final class UnicorncrewSyliusComgatePlugin extends Bundle
{
    use SyliusPluginTrait;

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new RegisterRefundPluginGatewayPass());
    }

    // Overridden to keep the alias "unicorncrew_sylius_comgate" explicit and stable.
    public function getContainerExtension(): ?ExtensionInterface
    {
        return $this->createContainerExtension();
    }
}
