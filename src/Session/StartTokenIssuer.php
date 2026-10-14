<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Session;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Issues a single-use start token for a PunchOut session.
 *
 * The token (32 random bytes, base64url) is returned to the caller (to embed in the
 * StartPage URL) but is NOT stored. Only the SHA-256 hash and an expiry timestamp
 * are persisted, so a database breach does not expose usable tokens.
 */
final readonly class StartTokenIssuer
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Issues a token, stores its SHA-256 hash in the session, and returns the plain token.
     *
     * @param int $ttlSeconds Token time-to-live in seconds (default: 300 = 5 min)
     */
    public function issue(PunchoutSession $session, int $ttlSeconds = 300): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);

        $session->setStartTokenHash($hash);
        $session->setStartTokenExpiry(new \DateTimeImmutable('+' . $ttlSeconds . ' seconds'));
        $session->setStatus(PunchoutSession::STATUS_PENDING);

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $token;
    }
}
