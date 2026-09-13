<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin\Payum;

use Comgate\SDK\Entity\Codes\PaymentStatusCode;

/**
 * Payment states as stored in Payment::$details['status'].
 *
 * The three real Comgate values are re-exported from {@see PaymentStatusCode}; {@see self::NEW}
 * is a synthetic value used before the payment has ever been created at Comgate.
 */
final class ComgateStatus
{
    public const NEW = 'new';

    public const PENDING = PaymentStatusCode::PENDING;

    public const PAID = PaymentStatusCode::PAID;

    public const CANCELLED = PaymentStatusCode::CANCELLED;

    public const AUTHORIZED = PaymentStatusCode::AUTHORIZED;

    private function __construct()
    {
    }
}
