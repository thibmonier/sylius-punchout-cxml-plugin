<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Classification;

/** What a returned line carries: UNSPSC code (cXML Classification) and unit of measure. */
final readonly class ItemClassification
{
    public function __construct(
        public string $unspsc,
        public string $unit,
    ) {
    }
}
