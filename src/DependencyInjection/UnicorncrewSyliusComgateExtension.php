<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class UnicorncrewSyliusComgateExtension extends Extension
{
    public function getAlias(): string
    {
        return 'unicorncrew_sylius_comgate';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');
    }
}
