<?php

declare(strict_types=1);

namespace Tests\Unicorncrew\SyliusComgatePlugin\Unit\Payum\Action;

use Comgate\SDK\Entity\Response\PaymentStatusResponse;
use Comgate\SDK\Http\Response;
use Nyholm\Psr7\Response as Psr7Response;
use Payum\Core\Gateway;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\Notify;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Unicorncrew\SyliusComgatePlugin\Api\ComgateApiInterface;
use Unicorncrew\SyliusComgatePlugin\Payum\Action\NotifyAction;
use Unicorncrew\SyliusComgatePlugin\Payum\ComgateStatus;

final class NotifyActionTest extends TestCase
{
    public function testItSupportsNotify(): void
    {
        $action = new NotifyAction($this->createMock(PaymentRepositoryInterface::class));

        self::assertTrue($action->supports(new Notify(null)));
    }

    public function testItRefreshesThePaymentStatusFromTheAuthoritativeApiCall(): void
    {
        $payment = new Payment();
        $payment->setDetails(['status' => ComgateStatus::PENDING, 'trans_id' => 'XXXX-YYYY-ZZZZ']);

        $paymentRepository = $this->createMock(PaymentRepositoryInterface::class);
        $paymentRepository->expects(self::once())->method('find')->with(42)->willReturn($payment);

        $api = $this->createMock(ComgateApiInterface::class);
        $api->expects(self::once())
            ->method('getStatus')
            ->with('XXXX-YYYY-ZZZZ')
            ->willReturn(new PaymentStatusResponse(new Response(new Psr7Response(200, [], json_encode([
                'code' => 0,
                'message' => 'OK',
                'transId' => 'XXXX-YYYY-ZZZZ',
                'status' => ComgateStatus::PAID,
            ], \JSON_THROW_ON_ERROR)))))
        ;

        $action = new NotifyAction($paymentRepository);
        $action->setApi($api);
        $action->setGateway($this->createNotifyWebhookGateway(['refId' => '42', 'transId' => 'XXXX-YYYY-ZZZZ']));

        $request = new Notify(null);
        $action->execute($request);

        self::assertSame(ComgateStatus::PAID, $payment->getDetails()['status']);
        self::assertSame('XXXX-YYYY-ZZZZ', $payment->getDetails()['trans_id']);
        self::assertSame($payment, $request->getFirstModel());
    }

    public function testItIgnoresNotificationsMissingTheReferenceOrTransactionId(): void
    {
        $paymentRepository = $this->createMock(PaymentRepositoryInterface::class);
        $paymentRepository->expects(self::never())->method('find');

        $api = $this->createMock(ComgateApiInterface::class);
        $api->expects(self::never())->method('getStatus');

        $action = new NotifyAction($paymentRepository);
        $action->setApi($api);
        $action->setGateway($this->createNotifyWebhookGateway(['refId' => '42']));

        $action->execute(new Notify(null));
    }

    public function testItIgnoresNotificationsForAnUnknownPayment(): void
    {
        $paymentRepository = $this->createMock(PaymentRepositoryInterface::class);
        $paymentRepository->expects(self::once())->method('find')->with(999)->willReturn(null);

        $api = $this->createMock(ComgateApiInterface::class);
        $api->expects(self::never())->method('getStatus');

        $action = new NotifyAction($paymentRepository);
        $action->setApi($api);
        $action->setGateway($this->createNotifyWebhookGateway(['refId' => '999', 'transId' => 'XXXX-YYYY-ZZZZ']));

        $action->execute(new Notify(null));
    }

    public function testItIgnoresNotificationsWhereTheTransactionIdDoesNotMatchThePayment(): void
    {
        $payment = new Payment();
        $payment->setDetails(['status' => ComgateStatus::PENDING, 'trans_id' => 'REAL-TRANS-ID']);

        $paymentRepository = $this->createMock(PaymentRepositoryInterface::class);
        $paymentRepository->expects(self::once())->method('find')->with(42)->willReturn($payment);

        $api = $this->createMock(ComgateApiInterface::class);
        $api->expects(self::never())->method('getStatus');

        $action = new NotifyAction($paymentRepository);
        $action->setApi($api);
        $action->setGateway($this->createNotifyWebhookGateway(['refId' => '42', 'transId' => 'ATTACKER-TRANS-ID']));

        $action->execute(new Notify(null));

        self::assertSame(ComgateStatus::PENDING, $payment->getDetails()['status']);
        self::assertSame('REAL-TRANS-ID', $payment->getDetails()['trans_id']);
    }

    public function testItIgnoresNotificationsForAPaymentThatHasNoTransactionIdYet(): void
    {
        $payment = new Payment();
        $payment->setDetails(['status' => ComgateStatus::NEW]);

        $paymentRepository = $this->createMock(PaymentRepositoryInterface::class);
        $paymentRepository->expects(self::once())->method('find')->with(42)->willReturn($payment);

        $api = $this->createMock(ComgateApiInterface::class);
        $api->expects(self::never())->method('getStatus');

        $action = new NotifyAction($paymentRepository);
        $action->setApi($api);
        $action->setGateway($this->createNotifyWebhookGateway(['refId' => '42', 'transId' => 'ATTACKER-TRANS-ID']));

        $action->execute(new Notify(null));

        self::assertSame(ComgateStatus::NEW, $payment->getDetails()['status']);
    }

    /** @param array<string, string> $requestParams */
    private function createNotifyWebhookGateway(array $requestParams): Gateway
    {
        $gateway = new Gateway();
        $gateway->addAction(new class($requestParams) implements \Payum\Core\Action\ActionInterface {
            public function __construct(private readonly array $params)
            {
            }

            /** @param GetHttpRequest $request */
            public function execute($request): void
            {
                $request->query = [];
                $request->request = $this->params;
                $request->method = 'POST';
            }

            public function supports($request): bool
            {
                return $request instanceof GetHttpRequest;
            }
        });

        return $gateway;
    }
}
