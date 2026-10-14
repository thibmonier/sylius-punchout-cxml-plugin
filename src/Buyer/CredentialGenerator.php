<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Buyer;

/**
 * Generates a cryptographically secure buyer shared secret.
 * Output: 32 bytes encoded as base64url (43 characters, no padding).
 */
final class CredentialGenerator
{
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
