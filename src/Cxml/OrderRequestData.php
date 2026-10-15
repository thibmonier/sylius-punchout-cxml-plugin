<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cxml;

final readonly class OrderRequestData
{
    /** @param list<OrderRequestItem> $items */
    public function __construct(
        public string $orderId,
        public string $type,
        public CxmlAddress $shipTo,
        public CxmlAddress $billTo,
        public array $items,
        public string $comments = '',
    ) {
    }
}
