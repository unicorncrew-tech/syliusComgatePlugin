<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin\Payum\Action;

use Comgate\SDK\Entity\Codes\CurrencyCode;
use Comgate\SDK\Entity\Codes\PaymentMethodCode;
use Comgate\SDK\Entity\Money;
use Comgate\SDK\Entity\Payment as ComgatePayment;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use Sylius\Component\Core\Model\PaymentInterface;
use Unicorncrew\SyliusComgatePlugin\Api\ComgateApiInterface;
use Unicorncrew\SyliusComgatePlugin\Payum\ComgateStatus;

final class CaptureAction implements ActionInterface, ApiAwareInterface
{
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = ComgateApiInterface::class;
    }

    /** @param Capture $request */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        // The payer already has been redirected once: refresh the status against
        // Comgate rather than trusting the browser is telling the truth.
        if (isset($details['trans_id']) && '' !== $details['trans_id']) {
            $status = $this->api->getStatus((string) $details['trans_id']);
            $details['status'] = $status->getStatus();

            return;
        }

        /** @var PaymentInterface|null $payment */
        $payment = $request->getFirstModel();
        if (!$payment instanceof PaymentInterface) {
            throw new LogicException('CaptureAction requires the first model to be a Sylius PaymentInterface.');
        }

        $order = $payment->getOrder();
        if (null === $order) {
            throw new LogicException('CaptureAction requires the payment to be attached to an order.');
        }

        $currencyCode = (string) $payment->getCurrencyCode();
        if (!\in_array($currencyCode, CurrencyCode::SELF, true)) {
            throw new LogicException(\sprintf(
                'Comgate does not support the "%s" currency. Supported currencies: %s.',
                $currencyCode,
                implode(', ', CurrencyCode::SELF),
            ));
        }

        $token = $request->getToken();
        $returnUrl = null !== $token ? $token->getTargetUrl() : null;

        $comgatePayment = new ComgatePayment();
        $comgatePayment
            ->setPrice(Money::ofCents((int) $payment->getAmount()))
            ->setCurrency($currencyCode)
            ->setLabel((string) $order->getNumber())
            ->setReferenceId((string) $payment->getId())
            ->setEmail($order->getCustomer()?->getEmail() ?? '')
            ->addMethod(PaymentMethodCode::ALL)
            ->setTest($this->api->isTest())
            ->setRedirect()
        ;

        if (null !== $returnUrl) {
            $comgatePayment
                ->setUrlPaidRedirect($returnUrl)
                ->setUrlCancelledRedirect($returnUrl)
                ->setUrlPendingRedirect($returnUrl)
            ;
        }

        $response = $this->api->createPayment($comgatePayment);

        $details['trans_id'] = $response->getTransId();
        $details['status'] = ComgateStatus::PENDING;

        throw new HttpRedirect($response->getRedirect());
    }

    public function supports($request): bool
    {
        return
            $request instanceof Capture &&
            $request->getModel() instanceof \ArrayAccess
        ;
    }
}
