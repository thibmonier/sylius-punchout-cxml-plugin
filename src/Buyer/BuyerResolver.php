<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Buyer;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves a Buyer by matching the cXML From and Sender identities.
 */
final readonly class BuyerResolver
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Returns the Buyer whose From AND Sender identities match, or null.
     */
    public function resolveByIdentities(
        string $fromDomain,
        string $fromIdentity,
        string $senderDomain,
        string $senderIdentity,
    ): ?BuyerInterface {
        // Find buyers that have both a From and a Sender identity matching.
        /** @var array<BuyerInterface> $buyers */
        $buyers = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(Buyer::class, 'b')
            ->join('b.identities', 'from_id', 'WITH', 'from_id.role = :fromRole AND from_id.domain = :fromDomain AND from_id.identity = :fromIdentity')
            ->join('b.identities', 'sender_id', 'WITH', 'sender_id.role = :senderRole AND sender_id.domain = :senderDomain AND sender_id.identity = :senderIdentity')
            ->setParameter('fromRole', 'From')
            ->setParameter('fromDomain', $fromDomain)
            ->setParameter('fromIdentity', $fromIdentity)
            ->setParameter('senderRole', 'Sender')
            ->setParameter('senderDomain', $senderDomain)
            ->setParameter('senderIdentity', $senderIdentity)
            ->setMaxResults(1)
            ->getQuery()
            ->getResult()
        ;

        return $buyers[0] ?? null;
    }
}
