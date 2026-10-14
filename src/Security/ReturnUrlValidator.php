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
final readonly class ReturnUrlValidator
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
     * @param list<string> $insecureHosts development-only hosts allowed over HTTP and on local addresses
     */
    public function __construct(private array $insecureHosts = [])
    {
    }

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
        $parsed = $url === '' ? false : parse_url($url);
        // No credentials in a return URL; the host must be a real host name or IP address.
        if ($parsed === false || isset($parsed['user']) || isset($parsed['pass'])) {
            return false;
        }

        // Normalize host to lowercase before any comparison (hostnames are case-insensitive)
        $host = strtolower($parsed['host'] ?? '');
        if (!$this->isWellFormedHost($host)) {
            return false;
        }

        $scheme = $parsed['scheme'] ?? '';
        if (in_array($host, $this->insecureHosts, true)) {
            // Development hosts may use HTTP and local addresses, never escape the buyer's domains.
            return in_array($scheme, ['http', 'https'], true) && $this->isAllowedForBuyer($host, $buyer);
        }

        if ($scheme !== 'https') {
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

        return $this->isAllowedForBuyer($host, $buyer);
    }

    /**
     * A dotted host name whose last label starts with a letter, or a literal IP address: numeric or
     * hexadecimal host forms (2130706433, 0x7f000001) and characters such as "\" are refused.
     */
    private function isWellFormedHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }
        if (filter_var(trim($host, '[]'), \FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (in_array($host, $this->insecureHosts, true)) {
            return filter_var($host, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME) !== false;
        }

        return filter_var($host, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME) !== false &&
            1 === preg_match('/\.[a-z][a-z0-9-]*$/', $host);
    }

    /** Deny by default (US-004): a buyer without allowed domain accepts no return URL. */
    private function isAllowedForBuyer(string $host, ?BuyerInterface $buyer): bool
    {
        if (!$buyer instanceof BuyerInterface) {
            return true;
        }

        return $this->hostMatchesDomains($host, $buyer->getReturnDomains());
    }

    private function isBlockedIp(string $host): bool
    {
        // IPv6 loopback and unspecified address (already lowercased by caller)
        if (in_array($host, ['::1', '[::1]', '::', '[::]'], true)) {
            return true;
        }

        // Strip brackets for IPv6
        $ip = trim($host, '[]');

        // IPv6-mapped IPv4 (::ffff:x.x.x.x) — always block as SSRF vector
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $ipv4 = substr($ip, 7);

            // Block even if the IPv4 part is not a valid address (malformed SSRF attempt)
            return true;
        }

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
            $domain = strtolower(trim($domain));
            // "*.ariba.com": its subdomains; "ariba.com": exactly this host.
            if (str_starts_with($domain, '*.') ? str_ends_with($host, substr($domain, 1)) : $host === $domain) {
                return true;
            }
        }

        return false;
    }
}
