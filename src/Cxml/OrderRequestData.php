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
        public bool $testMode = false,
    ) {
    }

    /** SHA-256 of what makes the order: items (part, quantity, price, currency) and addresses. */
    public function contentHash(): string
    {
        return hash('sha256', (string) json_encode([
            array_map(static fn (OrderRequestItem $item): array => [$item->variantCode, $item->quantity, $item->unitPrice, $item->currency], $this->items),
            $this->shipTo,
            $this->billTo,
        ]));
    }
}
