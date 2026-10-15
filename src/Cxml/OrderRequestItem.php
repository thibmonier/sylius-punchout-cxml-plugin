<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cxml;

/** An ItemOut of an OrderRequest. */
final readonly class OrderRequestItem
{
    public function __construct(
        public string $lineNumber,
        /** "v1:<variant code>" written by the transfer, or the part number */
        public string $variantCode,
        public int $quantity,
        public string $unitPrice,
        public string $currency,
    ) {
    }
}
