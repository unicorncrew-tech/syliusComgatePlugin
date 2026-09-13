<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin\Payum;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;
use Unicorncrew\SyliusComgatePlugin\Api\ComgateApi;
use Unicorncrew\SyliusComgatePlugin\Api\ComgateApiInterface;

final class ComgateGatewayFactory extends GatewayFactory
{
    protected function populateConfig(ArrayObject $config): void
    {
        $config->defaults([
            'payum.factory_name' => 'comgate',
            'payum.factory_title' => 'Comgate',
        ]);

        if (false == $config['payum.api']) {
            $config['payum.default_options'] = [
                'merchant' => '',
                'secret' => '',
                'test' => false,
            ];
            $config->defaults($config['payum.default_options']);
            $config['payum.required_options'] = ['merchant', 'secret'];

            $config['payum.api'] = function (ArrayObject $config): ComgateApiInterface {
                $config->validateNotEmpty($config['payum.required_options']);

                return new ComgateApi([
                    'merchant' => $config['merchant'],
                    'secret' => $config['secret'],
                    'test' => $config['test'],
                ]);
            };
        }
    }
}
