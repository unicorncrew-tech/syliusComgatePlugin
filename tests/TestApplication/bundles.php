<?php

declare(strict_types=1);

// Enabled on top of the bundles already registered by vendor/sylius/test-application/config/bundles.php
// (which already includes payum/payum-bundle and sylius/payum-bundle).

return [
    Unicorncrew\SyliusComgatePlugin\UnicorncrewSyliusComgatePlugin::class => ['all' => true],
];
