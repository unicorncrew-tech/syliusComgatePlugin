<?php

declare(strict_types=1);

namespace Tests\Unicorncrew\SyliusComgatePlugin\Unit\Payum\Action;

use Comgate\SDK\Entity\Refund;
use Comgate\SDK\Entity\Response\RefundResponse;
use Comgate\SDK\Http\Response;
use Nyholm\Psr7\Response as Psr7Response;
use Payum\Core\Exception\LogicException;
use Payum\Core\Request\Refund as PayumRefund;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Payment;
use Unicorncrew\SyliusComgatePlugin\Api\ComgateApiInterface;
use Unicorncrew\SyliusComgatePlugin\Payum\Action\RefundAction;
use Unicorncrew\SyliusComgatePlugin\Payum\Request\RefundPayment;

final class RefundActionTest extends TestCase
{
    public function testItOnlySupportsRefundsCarryingAnAmount(): void
    {
        $action = new RefundAction();

        self::assertTrue($action->supports(new RefundPayment($this->createPayment(['trans_id' => 'XXXX-YYYY-ZZZZ']), 100)));
        self::assertFalse($action->supports(new PayumRefund($this->createPayment(['trans_id' => 'XXXX-YYYY-ZZZZ']))));
    }

    public function testItRefundsTheRequestedAmountOfTheComgateTransaction(): void
    {
        $payment = $this->createPayment(['trans_id' => 'XXXX-YYYY-ZZZZ']);

        $api = $this->createMock(ComgateApiInterface::class);
        $api->method('isTest')->willReturn(true);
        $api->expects(self::once())
            ->method('refundPayment')
            ->with(self::callback(function (Refund $refund): bool {
                self::assertSame('XXXX-YYYY-ZZZZ', $refund->getTransId());
                self::assertSame(4500, $refund->getAmount()->get());
                self::assertSame('CZK', $refund->getCurrency());
                self::assertTrue($refund->isTest());

                return true;
            }))
            ->willReturn($this->createRefundResponse())
        ;

        $action = new RefundAction();
        $action->setApi($api);

        $action->execute(new RefundPayment($payment, 4500));
    }

    public function testItRejectsAPaymentThatWasNeverCreatedAtComgate(): void
    {
        $api = $this->createMock(ComgateApiInterface::class);
        $api->expects(self::never())->method('refundPayment');

        $action = new RefundAction();
        $action->setApi($api);

        $this->expectException(LogicException::class);

        $action->execute(new RefundPayment($this->createPayment([]), 4500));
    }

    public function testItRejectsANonPositiveAmountBeforeCallingComgate(): void
    {
        $api = $this->createMock(ComgateApiInterface::class);
        $api->expects(self::never())->method('refundPayment');

        $action = new RefundAction();
        $action->setApi($api);

        $this->expectException(LogicException::class);

        $action->execute(new RefundPayment($this->createPayment(['trans_id' => 'XXXX-YYYY-ZZZZ']), 0));
    }

    /** @param array<string, mixed> $details */
    private function createPayment(array $details): Payment
    {
        $payment = new Payment();
        $payment->setAmount(12345);
        $payment->setCurrencyCode('CZK');
        $payment->setDetails($details);

        return $payment;
    }

    private function createRefundResponse(): RefundResponse
    {
        return new RefundResponse(new Response(new Psr7Response(200, [], json_encode([
            'code' => 0,
            'message' => 'OK',
        ], \JSON_THROW_ON_ERROR))));
    }
}
