<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cxml;

/**
 * Optional parts of a PunchOutOrderMessage: the language of the descriptions and, when the buyer
 * wants them (US-019), the tax and the shipping of the cart (decimal strings, null = not sent).
 */
final readonly class MessageOptions
{
    public function __construct(
        public string $language = 'en',
        public ?string $tax = null,
        public ?string $shipping = null,
    ) {
    }
}
