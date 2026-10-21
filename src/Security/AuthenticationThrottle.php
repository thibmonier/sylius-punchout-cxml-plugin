<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Failed authentications of a buyer identity (cXML From credential, OCI buyer code), counted per
 * identity and address and per identity from all addresses.
 *
 * Only the budget of one address blocks before verification: a spent budget of the identity alone
 * must not lock the real buyer out (anyone knowing the identity could spend it). It only turns the
 * next failures into 429; right credentials still pass. Secrets are generated or at least 16 non
 * trivial characters, so the verification itself is not what a distributed attack can win.
 */
final readonly class AuthenticationThrottle
{
    public function __construct(
        private RateLimiterFactoryInterface $authenticationFailureLimiter,
        private RateLimiterFactoryInterface $identityFailureLimiter,
        private LoggerInterface $logger,
    ) {
    }

    /** Whether this address has spent its failure budget for this identity (no verification then). */
    public function isBlocked(string $identity, ?string $ip): bool
    {
        $blocked = $this->authenticationFailureLimiter->create($identity . '|' . RateLimitKeys::ip($ip))->consume(0)->getRemainingTokens() === 0;
        if ($blocked) {
            $this->logger->warning('PunchOut authentication blocked', ['identity' => $identity, 'ip' => $ip]);
        }

        return $blocked;
    }

    /**
     * Counts a failed authentication.
     *
     * @return bool whether the identity has spent its budget for all addresses (answer 429)
     */
    public function fail(string $identity, ?string $ip): bool
    {
        $this->authenticationFailureLimiter->create($identity . '|' . RateLimitKeys::ip($ip))->consume();
        $spent = !$this->identityFailureLimiter->create($identity)->consume()->isAccepted();
        if ($spent) {
            $this->logger->warning('PunchOut authentication failures of an identity from many addresses', ['identity' => $identity, 'ip' => $ip]);
        }

        return $spent;
    }
}
