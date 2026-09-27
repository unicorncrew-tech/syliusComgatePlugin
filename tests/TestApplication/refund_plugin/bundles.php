<?php

declare(strict_types=1);

// Bundles enabled on top of tests/TestApplication/bundles.php by RefundPluginIntegrationTest only, so
// the rest of the suite keeps covering a shop without sylius/refund-plugin.

return [
    Unicorncrew\SyliusComgatePlugin\UnicorncrewSyliusComgatePlugin::class => ['all' => true],
    Knp\Bundle\SnappyBundle\KnpSnappyBundle::class => ['all' => true],
    Sylius\RefundPlugin\SyliusRefundPlugin::class => ['all' => true],
];
