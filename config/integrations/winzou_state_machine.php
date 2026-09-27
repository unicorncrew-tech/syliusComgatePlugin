<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// Only apps that map the `sylius_payment` graph to winzou_state_machine need this;
// with the default Symfony Workflow adapter, the refund is triggered by the
// `workflow.sylius_payment.transition.refund` listener (config/services/refund.yaml).
return static function (ContainerConfigurator $configurator, ContainerBuilder $container): void {
    /** @var array<string, class-string> $bundles */
    $bundles = $container->getParameter('kernel.bundles');

    if (isset($bundles['winzouStateMachineBundle'])) {
        $configurator->import('winzou_state_machine/*.yaml');
    }
};
