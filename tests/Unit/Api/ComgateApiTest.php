<?php

declare(strict_types=1);

namespace Tests\Unicorncrew\SyliusComgatePlugin\Unit\Api;

use PHPUnit\Framework\TestCase;
use Unicorncrew\SyliusComgatePlugin\Api\ComgateApi;
use Webmozart\Assert\InvalidArgumentException;

final class ComgateApiTest extends TestCase
{
    public function testItIsInTestModeWhenConfiguredWithTestFlag(): void
    {
        $api = new ComgateApi(['merchant' => '123456', 'secret' => 'foobarbaz', 'test' => true]);

        self::assertTrue($api->isTest());
    }

    public function testItIsNotInTestModeByDefault(): void
    {
        $api = new ComgateApi(['merchant' => '123456', 'secret' => 'foobarbaz']);

        self::assertFalse($api->isTest());
    }

    public function testItRejectsAMissingMerchant(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ComgateApi(['secret' => 'foobarbaz']);
    }

    public function testItRejectsAnEmptySecret(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ComgateApi(['merchant' => '123456', 'secret' => '']);
    }
}
