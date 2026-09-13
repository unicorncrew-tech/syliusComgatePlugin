<?php

declare(strict_types=1);

namespace Tests\Unicorncrew\SyliusComgatePlugin\Unit\Payum\Action;

use Payum\Core\Bridge\Spl\ArrayObject;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\PayumBundle\Request\GetStatus;
use Sylius\Component\Payment\Model\PaymentInterface;
use Unicorncrew\SyliusComgatePlugin\Payum\Action\StatusAction;
use Unicorncrew\SyliusComgatePlugin\Payum\ComgateStatus;

final class StatusActionTest extends TestCase
{
    private StatusAction $action;

    protected function setUp(): void
    {
        $this->action = new StatusAction();
    }

    public function testItSupportsGetStatusWithArrayModel(): void
    {
        self::assertTrue($this->action->supports(new GetStatus(new ArrayObject([]))));
    }

    public function testItDoesNotSupportGetStatusWithNonArrayModel(): void
    {
        self::assertFalse($this->action->supports(new GetStatus(new \stdClass())));
    }

    /** @dataProvider statusMappingProvider */
    public function testItMapsComgateStatusToSyliusPaymentState(string $comgateStatus, string $expectedSyliusState): void
    {
        $request = new GetStatus(new ArrayObject(['status' => $comgateStatus]));

        $this->action->execute($request);

        self::assertSame($expectedSyliusState, $request->getValue());
    }

    public static function statusMappingProvider(): iterable
    {
        yield 'no status yet defaults to new' => [ComgateStatus::NEW, PaymentInterface::STATE_NEW];
        yield 'pending maps to processing' => [ComgateStatus::PENDING, PaymentInterface::STATE_PROCESSING];
        yield 'authorized' => [ComgateStatus::AUTHORIZED, PaymentInterface::STATE_AUTHORIZED];
        yield 'paid maps to completed' => [ComgateStatus::PAID, PaymentInterface::STATE_COMPLETED];
        yield 'cancelled' => [ComgateStatus::CANCELLED, PaymentInterface::STATE_CANCELLED];
        yield 'unmapped value falls back to unknown' => ['SOME_UNMAPPED_VALUE', PaymentInterface::STATE_UNKNOWN];
    }
}
