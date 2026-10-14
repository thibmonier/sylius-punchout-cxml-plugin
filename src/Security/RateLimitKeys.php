<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Security;

/** Rate limiter keys that the sender cannot multiply for free. */
final class RateLimitKeys
{
    /**
     * IPv4 address (also when written as an IPv4-mapped IPv6 address, ::ffff:a.b.c.d), or the /56
     * network of an IPv6 address: ISPs commonly give a whole /56 (or /64) to one subscriber.
     */
    public static function ip(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return 'unknown';
        }
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 7) . str_repeat("\0", 9)) . '/56';
    }

    private function __construct()
    {
    }
}
