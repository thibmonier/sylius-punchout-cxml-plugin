<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Session;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Looks up a PunchOut session by its start token and consumes it atomically.
 *
 * "Atomic" means we UPDATE status = 'active' WHERE status = 'pending' AND id = ?
 * so that two simultaneous requests with the same token cannot both succeed.
 *
 * HEAD requests and browser prefetches (Sec-Purpose: prefetch / Purpose: prefetch)
 * must call findValid() instead, which does NOT consume the token.
 */
final readonly class StartTokenConsumer
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Finds the session for a token without consuming it.
     * Returns null if the token is unknown, expired, or already consumed.
     */
    public function findValid(string $token): ?PunchoutSession
    {
        $hash = hash('sha256', $token);

        $result = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(PunchoutSession::class, 's')
            ->where('s.startTokenHash = :hash')
            ->andWhere('s.status = :status')
            ->andWhere('s.startTokenExpiry > :now')
            ->setParameter('hash', $hash)
            ->setParameter('status', PunchoutSession::STATUS_PENDING)
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $result instanceof PunchoutSession ? $result : null;
    }

    /**
     * Atomically transitions the session from pending to active.
     * Returns null if the token is unknown, expired, or already consumed.
     */
    public function consume(string $token): ?PunchoutSession
    {
        $session = $this->findValid($token);
        if (!$session instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession) {
            return null;
        }

        // Atomic transition: only update if still pending (guards against race conditions)
        $updated = $this->entityManager->createQueryBuilder()
            ->update(PunchoutSession::class, 's')
            ->set('s.status', ':active')
            ->where('s.id = :id')
            ->andWhere('s.status = :pending')
            ->setParameter('active', PunchoutSession::STATUS_ACTIVE)
            ->setParameter('pending', PunchoutSession::STATUS_PENDING)
            ->setParameter('id', $session->getId())
            ->getQuery()
            ->execute()
        ;

        if ($updated === 0) {
            return null;
        }

        // Refresh to get the updated status
        $this->entityManager->refresh($session);

        return $session;
    }
}
