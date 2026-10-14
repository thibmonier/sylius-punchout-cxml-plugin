<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cxml;

/**
 * Generates unique cXML payloadID values.
 * Format: <unix-timestamp>.<random-hex>@<hostname>
 */
final class PayloadIdGenerator
{
    public function generate(string $hostname = 'localhost'): string
    {
        return sprintf('%d.%s@%s', time(), bin2hex(random_bytes(8)), $hostname);
    }
}
