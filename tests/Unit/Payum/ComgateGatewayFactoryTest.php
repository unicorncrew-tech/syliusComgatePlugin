<?php

declare(strict_types=1);

namespace Tests\Unicorncrew\SyliusComgatePlugin\Unit\Payum;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayInterface;
use PHPUnit\Framework\TestCase;
use Unicorncrew\SyliusComgatePlugin\Api\ComgateApiInterface;
use Unicorncrew\SyliusComgatePlugin\Payum\ComgateGatewayFactory;

final class ComgateGatewayFactoryTest extends TestCase
{
    public function testItBuildsAGatewayNamedComgate(): void
    {
        $factory = new ComgateGatewayFactory();

        $gateway = $factory->create(['merchant' => '123456', 'secret' => 'foobarbaz']);

        self::assertInstanceOf(GatewayInterface::class, $gateway);
    }

    public function testItLazilyBuildsAComgateApiFromTheGivenConfig(): void
    {
        $factory = new ComgateGatewayFactory();

        $config = ArrayObject::ensureArrayObject(
            $factory->createConfig(['merchant' => '123456', 'secret' => 'foobarbaz', 'test' => true]),
        );

        self::assertIsCallable($config['payum.api']);

        $api = $config['payum.api']($config);

        self::assertInstanceOf(ComgateApiInterface::class, $api);
        self::assertTrue($api->isTest());
    }

    public function testItRequiresMerchantAndSecret(): void
    {
        $factory = new ComgateGatewayFactory();

        $this->expectException(\Payum\Core\Exception\LogicException::class);

        $factory->create([]);
    }
}
