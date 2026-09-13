<?php

declare(strict_types=1);

namespace Unicorncrew\SyliusComgatePlugin\Api;

use Comgate\SDK\Client;
use Comgate\SDK\Comgate;
use Comgate\SDK\Entity\Payment;
use Comgate\SDK\Entity\Response\PaymentCreateResponse;
use Comgate\SDK\Entity\Response\PaymentStatusResponse;
use Webmozart\Assert\Assert;

final class ComgateApi implements ComgateApiInterface
{
    private readonly Client $client;

    private readonly bool $test;

    /** @param array<string, mixed> $options */
    public function __construct(array $options)
    {
        Assert::keyExists($options, 'merchant');
        Assert::keyExists($options, 'secret');
        Assert::stringNotEmpty($options['merchant']);
        Assert::stringNotEmpty($options['secret']);

        $this->client = Comgate::defaults()
            ->setMerchant($options['merchant'])
            ->setSecret($options['secret'])
            ->createClient()
        ;
        $this->test = (bool) ($options['test'] ?? false);
    }

    public function isTest(): bool
    {
        return $this->test;
    }

    public function createPayment(Payment $payment): PaymentCreateResponse
    {
        return $this->client->createPayment($payment);
    }

    public function getStatus(string $transactionId): PaymentStatusResponse
    {
        return $this->client->getStatus($transactionId);
    }
}
