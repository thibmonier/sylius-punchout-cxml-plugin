<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Order;

use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\CxmlStatus;

/** An OrderRequest the shop does not turn into an order, with the cXML status to answer. */
final class OrderRequestRejected extends \RuntimeException
{
    public function __construct(string $message, private readonly int $cxmlStatus = CxmlStatus::BAD_REQUEST)
    {
        parent::__construct($message);
    }

    public function getCxmlStatus(): int
    {
        return $this->cxmlStatus;
    }
}
