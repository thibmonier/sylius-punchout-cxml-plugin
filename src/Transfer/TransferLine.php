<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Transfer;

/**
 * Immutable DTO representing one line of a PunchOut transfer.
 */
final readonly class TransferLine
{
    public function __construct(
        public string $variantCode,
        public string $supplierPartAuxId,
        public string $description,
        public int $quantity,
        /** Net unit price formatted as a decimal string (e.g. "100.00") */
        public string $unitNetPrice,
        public string $currency,
        public string $unitOfMeasure = 'EA',
        public ?string $unspsc = null,
        public ?string $manufacturer = null,
    ) {
    }
}
