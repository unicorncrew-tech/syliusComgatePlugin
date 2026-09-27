<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin\Refund;

use Comgate\SDK\Exception\ApiException;
use Comgate\SDK\Exception\RuntimeException;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Resource\Exception\UpdateHandlingException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Webmozart\Assert\Assert;

/**
 * Backs Sylius' own admin payment "Refund" button (the `refund` transition of the
 * `sylius_payment` state machine) with a full refund at Comgate.
 *
 * Runs before the transition is applied: a Comgate failure is rethrown as an
 * {@see UpdateHandlingException}, which makes Sylius' resource controller abort
 * the transition instead of marking an unrefunded payment as refunded.
 */
final class RefundPaymentListener
{
    public function __construct(
        private readonly ComgatePaymentRefunder $refunder,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** Symfony Workflow listener: `workflow.sylius_payment.transition.refund`. */
    public function __invoke(TransitionEvent $event): void
    {
        $payment = $event->getSubject();
        Assert::isInstanceOf($payment, PaymentInterface::class);

        $this->refund($payment);
    }

    /** winzou state machine callback: `sylius_payment` / before `refund`. */
    public function refund(PaymentInterface $payment): void
    {
        // Non-Comgate payments, and Comgate payments completed by hand (no
        // Comgate transaction to return money from), are left to the state change.
        if (!$this->refunder->supports($payment)) {
            return;
        }

        try {
            $this->refunder->refund($payment, (int) $payment->getAmount());
        } catch (ApiException|RuntimeException $exception) {
            $this->addFailureFlash($exception->getMessage());

            throw new UpdateHandlingException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * The admin refund route pins its flash to "sylius.payment.refunded", so the resource
     * controller reports the aborted transition with that (success) wording; this flash
     * is what tells the admin the refund failed, and why.
     */
    private function addFailureFlash(string $reason): void
    {
        $session = $this->requestStack->getMainRequest()?->hasSession() === true ? $this->requestStack->getSession() : null;
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $session->getFlashBag()->add('error', [
            'message' => 'unicorncrew_sylius_comgate.refund_failed',
            'parameters' => ['%reason%' => $reason],
        ]);
    }
}
