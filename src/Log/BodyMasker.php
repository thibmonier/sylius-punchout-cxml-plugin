<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Log;

/**
 * Masks what a logged exchange must never show (US-024): shared secrets and passwords entirely,
 * e-mail addresses down to their first letter and domain (personal data, GDPR).
 */
final class BodyMasker
{
    private const EMAIL = '/\b([A-Za-z0-9])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/';

    public function mask(string $body): string
    {
        $body = (string) preg_replace('#<(SharedSecret|Password)>.*?</\1>#s', '<$1>***</$1>', $body);

        return (string) preg_replace(self::EMAIL, '$1***@$2', $body);
    }
}
