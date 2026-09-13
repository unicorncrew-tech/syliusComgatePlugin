<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin\Payum\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Convert;
use Sylius\Component\Core\Model\PaymentInterface;
use Unicorncrew\SyliusComgatePlugin\Payum\ComgateStatus;

/**
 * Seeds Payment::$details the very first time a Payum Capture request is executed
 * for a given payment, before any call to Comgate has ever been made.
 */
final class ConvertPaymentAction implements ActionInterface
{
    /** @param Convert $request */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getSource();

        $details = ArrayObject::ensureArrayObject($payment->getDetails());
        $details->defaults([
            'status' => ComgateStatus::NEW,
        ]);

        $request->setResult((array) $details);
    }

    public function supports($request): bool
    {
        return
            $request instanceof Convert &&
            $request->getSource() instanceof PaymentInterface &&
            $request->getTo() === 'array'
        ;
    }
}
