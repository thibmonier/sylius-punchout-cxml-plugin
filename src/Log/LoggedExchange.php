<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Log;

use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;

/** What is known about a received request as it is processed, to log it once answered. */
final class LoggedExchange
{
    public string $messageType = 'unknown';

    public string $payloadId = '';

    public ?BuyerInterface $buyer = null;
}
