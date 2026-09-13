<?php

declare(strict_types=1);

namespace Tests\Unicorncrew\SyliusComgatePlugin\Unit\Payum\Action;

use Payum\Core\Request\Convert;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Payment;
use Unicorncrew\SyliusComgatePlugin\Payum\Action\ConvertPaymentAction;
use Unicorncrew\SyliusComgatePlugin\Payum\ComgateStatus;

final class ConvertPaymentActionTest extends TestCase
{
    private ConvertPaymentAction $action;

    protected function setUp(): void
    {
        $this->action = new ConvertPaymentAction();
    }

    public function testItSupportsConvertingAPaymentToArray(): void
    {
        $request = new Convert(new Payment(), 'array');

        self::assertTrue($this->action->supports($request));
    }

    public function testItDoesNotSupportConvertingToSomethingOtherThanArray(): void
    {
        $request = new Convert(new Payment(), 'json');

        self::assertFalse($this->action->supports($request));
    }

    public function testItSeedsFreshPaymentDetailsWithTheSyntheticNewStatus(): void
    {
        $payment = new Payment();
        $request = new Convert($payment, 'array');

        $this->action->execute($request);

        self::assertSame(['status' => ComgateStatus::NEW], $request->getResult());
    }

    public function testItNeverOverwritesAnAlreadyEstablishedStatus(): void
    {
        $payment = new Payment();
        $payment->setDetails(['status' => ComgateStatus::PAID, 'trans_id' => 'XXXX-YYYY-ZZZZ']);
        $request = new Convert($payment, 'array');

        $this->action->execute($request);

        self::assertSame(
            ['status' => ComgateStatus::PAID, 'trans_id' => 'XXXX-YYYY-ZZZZ'],
            $request->getResult(),
        );
    }
}
