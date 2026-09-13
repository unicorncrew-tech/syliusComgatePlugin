<?php

declare(strict_types=1);

namespace Tests\Unicorncrew\SyliusComgatePlugin\Unit\Payum\Action;

use Comgate\SDK\Entity\Payment as ComgatePayment;
use Comgate\SDK\Entity\Response\PaymentCreateResponse;
use Comgate\SDK\Entity\Response\PaymentStatusResponse;
use Comgate\SDK\Http\Response;
use Nyholm\Psr7\Response as Psr7Response;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Model\Token;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Unicorncrew\SyliusComgatePlugin\Api\ComgateApiInterface;
use Unicorncrew\SyliusComgatePlugin\Payum\Action\CaptureAction;
use Unicorncrew\SyliusComgatePlugin\Payum\ComgateStatus;

final class CaptureActionTest extends TestCase
{
    public function testItSupportsCaptureWithArrayModel(): void
    {
        $action = new CaptureAction();

        self::assertTrue($action->supports(new Capture(new ArrayObject([]))));
    }

    public function testItDoesNotSupportCaptureWithNonArrayModel(): void
    {
        $action = new CaptureAction();

        self::assertFalse($action->supports(new Capture(new \stdClass())));
    }

    public function testItCreatesAComgatePaymentAndRedirectsToTheHostedPaymentPage(): void
    {
        $payment = $this->createPayment(paymentId: 42, amount: 12345, currencyCode: 'CZK');

        $createResponse = $this->createPaymentCreateResponse('XXXX-YYYY-ZZZZ', 'https://payments.comgate.cz/client/instructions/index?id=XXXX-YYYY-ZZZZ');

        $api = $this->createMock(ComgateApiInterface::class);
        $api->expects(self::once())->method('isTest')->willReturn(true);
        $api->expects(self::once())
            ->method('createPayment')
            ->with(self::callback(function (ComgatePayment $comgatePayment) use ($payment): bool {
                self::assertSame(12345, $comgatePayment->getPrice()->get());
                self::assertSame('CZK', $comgatePayment->getCurrency());
                self::assertSame((string) $payment->getId(), $comgatePayment->getReferenceId());
                self::assertTrue($comgatePayment->isTest());
                self::assertSame('https://shop.example/return', $comgatePayment->getUrlPaidRedirect());

                return true;
            }))
            ->willReturn($createResponse)
        ;

        $action = new CaptureAction();
        $action->setApi($api);

        $token = new Token();
        $token->setTargetUrl('https://shop.example/return');

        $request = new Capture($token);
        $request->setModel($payment);
        $request->setModel($payment->getDetails());

        try {
            $action->execute($request);
            self::fail('Expected an HttpRedirect reply.');
        } catch (HttpRedirect $redirect) {
            self::assertSame('https://payments.comgate.cz/client/instructions/index?id=XXXX-YYYY-ZZZZ', $redirect->getUrl());
        }

        $details = (array) $request->getModel();
        self::assertSame('XXXX-YYYY-ZZZZ', $details['trans_id']);
        self::assertSame(ComgateStatus::PENDING, $details['status']);
    }

    public function testItRejectsAnUnsupportedCurrencyBeforeCallingComgate(): void
    {
        $payment = $this->createPayment(paymentId: 42, amount: 12345, currencyCode: 'JPY');

        $api = $this->createMock(ComgateApiInterface::class);
        $api->expects(self::never())->method('createPayment');

        $action = new CaptureAction();
        $action->setApi($api);

        $request = new Capture(new Token());
        $request->setModel($payment);
        $request->setModel($payment->getDetails());

        $this->expectException(\Payum\Core\Exception\LogicException::class);
        $this->expectExceptionMessage('Comgate does not support the "JPY" currency.');

        $action->execute($request);
    }

    public function testOnReturnItRefreshesStatusFromComgateInsteadOfTrustingTheBrowser(): void
    {
        $payment = $this->createPayment(paymentId: 42, amount: 12345, currencyCode: 'CZK');
        $payment->setDetails(['trans_id' => 'XXXX-YYYY-ZZZZ', 'status' => ComgateStatus::PENDING]);

        $api = $this->createMock(ComgateApiInterface::class);
        $api->expects(self::never())->method('createPayment');
        $api->expects(self::once())
            ->method('getStatus')
            ->with('XXXX-YYYY-ZZZZ')
            ->willReturn($this->createPaymentStatusResponse(ComgateStatus::PAID))
        ;

        $action = new CaptureAction();
        $action->setApi($api);

        $request = new Capture($payment->getDetails());

        $action->execute($request);

        self::assertSame(ComgateStatus::PAID, ((array) $request->getModel())['status']);
    }

    private function createPayment(int $paymentId, int $amount, string $currencyCode): Payment
    {
        $customer = new Customer();
        $customer->setEmail('foo@bar.tld');

        $order = new Order();
        $order->setNumber('000000042');
        $order->setCustomer($customer);

        $payment = new Payment();
        $payment->setOrder($order);
        $payment->setAmount($amount);
        $payment->setCurrencyCode($currencyCode);

        $reflection = new \ReflectionProperty($payment, 'id');
        $reflection->setValue($payment, $paymentId);

        return $payment;
    }

    private function createPaymentCreateResponse(string $transId, string $redirect): PaymentCreateResponse
    {
        return new PaymentCreateResponse(new Response(new Psr7Response(200, [], json_encode([
            'code' => 0,
            'message' => 'OK',
            'transId' => $transId,
            'redirect' => $redirect,
        ], \JSON_THROW_ON_ERROR))));
    }

    private function createPaymentStatusResponse(string $status): PaymentStatusResponse
    {
        return new PaymentStatusResponse(new Response(new Psr7Response(200, [], json_encode([
            'code' => 0,
            'message' => 'OK',
            'merchant' => '123456',
            'test' => 'true',
            'price' => 12345,
            'curr' => 'CZK',
            'label' => '000000042',
            'refId' => '42',
            'method' => 'CARD_CZ_COMGATE',
            'email' => 'foo@bar.tld',
            'transId' => 'XXXX-YYYY-ZZZZ',
            'status' => $status,
        ], \JSON_THROW_ON_ERROR))));
    }
}
