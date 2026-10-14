<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Security;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;

/**
 * Anti-replay of setup requests (US-008): the timestamp must be within the window around now and the
 * payloadID must never have been accepted for this buyer. A captured setup cannot open a second session.
 */
final readonly class ReplayGuard
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private int $window = 900,
    ) {
    }

    public function accept(Buyer $buyer, string $payloadId, string $timestamp): bool
    {
        try {
            $sentAt = new \DateTimeImmutable($timestamp);
        } catch (\Exception) {
            return false;
        }
        $now = new \DateTimeImmutable();
        if ($payloadId === '' || abs($now->getTimestamp() - $sentAt->getTimestamp()) > $this->window) {
            return false;
        }

        $hash = hash('sha256', $payloadId);
        $seen = $this->entityManager->getRepository(SeenPayload::class)->findOneBy(['buyer' => $buyer, 'payloadHash' => $hash]);
        if ($seen !== null) {
            return false;
        }

        try {
            $this->entityManager->persist(new SeenPayload($buyer, $hash, $now->modify(sprintf('+%d seconds', 2 * $this->window))));
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous requests with the same payloadID: only one gets through.
            return false;
        }

        return true;
    }
}
