<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Security;

use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;

/**
 * Validates PunchOut return URLs.
 *
 * Sprint 1 rules:
 * - HTTPS mandatory
 * - No literal private/reserved IP addresses
 * - Host must be in the buyer's returnDomains (when the list is non-empty)
 *
 * Full domain-whitelist enforcement is sprint 2; this class is already interface-ready.
 */
final class ReturnUrlValidator
{
    /**
     * IPv4 ranges that must never be a return-URL host.
     * Includes loopback, private, link-local, and reserved blocks.
     */
    private const BLOCKED_RANGES = [
        '10.',
        '172.16.',
        '172.17.',
        '172.18.',
        '172.19.',
        '172.20.',
        '172.21.',
        '172.22.',
        '172.23.',
        '172.24.',
        '172.25.',
        '172.26.',
        '172.27.',
        '172.28.',
        '172.29.',
        '172.30.',
        '172.31.',
        '192.168.',
        '127.',
        '169.254.',
        '0.',
        '100.64.',
    ];

    /**
     * @throws InvalidReturnUrlException
     */
    public function validate(string $url, ?BuyerInterface $buyer): void
    {
        if (!$this->isValid($url, $buyer)) {
            throw new InvalidReturnUrlException(sprintf('Return URL "%s" is not allowed', $url));
        }
    }

    public function isValid(string $url, ?BuyerInterface $buyer): bool
    {
        if ($url === '') {
            return false;
        }

        $parsed = parse_url($url);
        if ($parsed === false) {
            return false;
        }

        // HTTPS mandatory
        if (($parsed['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = $parsed['host'] ?? '';
        if ($host === '') {
            return false;
        }

        // Reject loopback hostnames
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) {
            return false;
        }

        // Reject literal private/reserved IPs
        if ($this->isBlockedIp($host)) {
            return false;
        }

        // Domain whitelist (sprint 1: only check if list is non-empty)
        if (!$buyer instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface) {
            return true;
        }

        $allowedDomains = $buyer->getReturnDomains();
        if ($allowedDomains === []) {
            return true;
        }

        return $this->hostMatchesDomains($host, $allowedDomains);
    }

    private function isBlockedIp(string $host): bool
    {
        // IPv6 loopback
        if ($host === '::1' || $host === '[::1]') {
            return true;
        }

        // Strip brackets for IPv6
        $ip = trim($host, '[]');

        // Is it an IPv4 address?
        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false) {
            foreach (self::BLOCKED_RANGES as $prefix) {
                if (str_starts_with($ip, $prefix)) {
                    return true;
                }
            }
        }

        // Is it an IPv6 address (private/local)?
        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) !== false) {
            // fc00::/7 (unique local), fe80::/10 (link-local)
            $lower = strtolower($ip);
            if (str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd') || str_starts_with($lower, 'fe8') || str_starts_with($lower, 'fe9') || str_starts_with($lower, 'fea') || str_starts_with($lower, 'feb')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $domains */
    private function hostMatchesDomains(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            // Exact match or subdomain match (.example.com matches sub.example.com)
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }
}
