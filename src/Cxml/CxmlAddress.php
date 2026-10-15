<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cxml;

/** An Address of an OrderRequest (ShipTo, BillTo): Name, PostalAddress and country. */
final readonly class CxmlAddress
{
    /** @param list<string> $street */
    public function __construct(
        public string $name,
        public string $deliverTo,
        public array $street,
        public string $city,
        public string $postalCode,
        public string $countryCode,
        public ?string $state = null,
    ) {
    }
}
