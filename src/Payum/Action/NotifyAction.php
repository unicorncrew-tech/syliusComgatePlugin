<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin\Payum\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\Notify;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Unicorncrew\SyliusComgatePlugin\Api\ComgateApiInterface;

/**
 * Handles the server-to-server "STATUS URL" webhook Comgate is configured (in
 * their merchant portal) to call at `payum_notify_do_unsafe/{gateway}`.
 *
 * The webhook payload is never trusted directly: it only carries enough
 * information (refId + transId) to look the payment up, the authoritative
 * status is always re-fetched through {@see ComgateApiInterface::getStatus()}.
 */
final class NotifyAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /** @param PaymentRepositoryInterface<PaymentInterface> $paymentRepository */
    public function __construct(private readonly PaymentRepositoryInterface $paymentRepository)
    {
        $this->apiClass = ComgateApiInterface::class;
    }

    /** @param Notify $request */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $httpRequest = new GetHttpRequest();
        $this->gateway->execute($httpRequest);

        $params = array_merge($httpRequest->query, $httpRequest->request);

        $paymentId = $params['refId'] ?? null;
        $transactionId = $params['transId'] ?? ($params['id'] ?? null);

        if (!is_string($paymentId) || '' === $paymentId || !is_string($transactionId) || '' === $transactionId) {
            return;
        }

        if (!ctype_digit($paymentId)) {
            return;
        }

        $payment = $this->paymentRepository->find((int) $paymentId);
        if (!$payment instanceof PaymentInterface) {
            return;
        }

        $details = $payment->getDetails();

        // The webhook body is attacker-controlled and Comgate's status webhook
        // carries no signature. Without this check, an attacker who knows (or
        // enumerates) a victim's payment id could pair it with a transId from
        // their own paid Comgate transaction and have it marked as completed.
        // trans_id is written by CaptureAction before the shopper is ever
        // redirected to Comgate, so a legitimate webhook always matches it.
        if (!isset($details['trans_id']) || $details['trans_id'] !== $transactionId) {
            return;
        }

        $status = $this->api->getStatus($transactionId);

        $details['status'] = $status->getStatus();
        $payment->setDetails($details);

        $request->setModel($payment);
    }

    public function supports($request): bool
    {
        return $request instanceof Notify;
    }
}
