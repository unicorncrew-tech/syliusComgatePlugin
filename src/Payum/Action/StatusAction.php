<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin\Payum\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\GetStatusInterface;
use Unicorncrew\SyliusComgatePlugin\Payum\ComgateStatus;

/**
 * Maps the Comgate status stored in Payment::$details['status'] (kept fresh by
 * CaptureAction and NotifyAction) onto Payum's generic payment status.
 */
final class StatusAction implements ActionInterface
{
    /** @param GetStatusInterface $request */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());
        $status = $details['status'] ?? ComgateStatus::NEW;

        match ($status) {
            ComgateStatus::NEW => $request->markNew(),
            ComgateStatus::PENDING => $request->markPending(),
            ComgateStatus::AUTHORIZED => $request->markAuthorized(),
            ComgateStatus::PAID => $request->markCaptured(),
            ComgateStatus::CANCELLED => $request->markCanceled(),
            default => $request->markUnknown(),
        };
    }

    public function supports($request): bool
    {
        return
            $request instanceof GetStatusInterface &&
            $request->getModel() instanceof \ArrayAccess
        ;
    }
}
