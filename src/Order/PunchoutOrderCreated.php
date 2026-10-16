<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Order;

use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;

/**
 * Dispatched once, after the commit, when an OrderRequest has created a Sylius order. A repost of the
 * same purchase order, or a refused one, dispatches nothing.
 *
 * Listen to it rather than reading the order notes: `purchaseOrderId` is the `orderID` of the buyer's
 * purchase order.
 */
final readonly class PunchoutOrderCreated
{
    public function __construct(
        public string $purchaseOrderId,
        public OrderInterface $order,
        public BuyerInterface $buyer,
    ) {
    }
}
