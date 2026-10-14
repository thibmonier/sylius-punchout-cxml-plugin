<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;

/**
 * Anti-replay of setup requests (US-008): the timestamp is an absolute ISO 8601 date with a time zone,
 * within the window around now, and the payloadID was never accepted for this buyer. A captured setup
 * cannot open a second session. Written with DBAL: no flush of the unit of work, no closed entity
 * manager on a concurrent duplicate.
 */
final readonly class ReplayGuard
{
    private const TABLE = 'tmonier_punchout_seen_payload';

    private const TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

    public function __construct(
        private Connection $connection,
        private int $window = 900,
    ) {
    }

    public function accept(Buyer $buyer, string $payloadId, string $timestamp): bool
    {
        if ($payloadId === '' || $buyer->getId() === null || 1 !== preg_match(self::TIMESTAMP, $timestamp)) {
            return false;
        }
        $now = new \DateTimeImmutable();
        if (abs($now->getTimestamp() - (new \DateTimeImmutable($timestamp))->getTimestamp()) > $this->window) {
            return false;
        }

        // The payloadIDs older than twice the window can no longer be replayed: forget them.
        $this->connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE expires_at < :now', ['now' => $now], ['now' => Types::DATETIME_IMMUTABLE]);

        $hash = hash('sha256', $payloadId);
        $seen = $this->connection->fetchOne('SELECT 1 FROM ' . self::TABLE . ' WHERE buyer_id = :buyer AND payload_hash = :hash', ['buyer' => $buyer->getId(), 'hash' => $hash]);
        if ($seen !== false) {
            return false;
        }

        try {
            $this->connection->insert(
                self::TABLE,
                ['buyer_id' => $buyer->getId(), 'payload_hash' => $hash, 'expires_at' => $now->modify(sprintf('+%d seconds', 2 * $this->window))],
                ['expires_at' => Types::DATETIME_IMMUTABLE],
            );
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous requests with the same payloadID: only one gets through.
            return false;
        }

        return true;
    }
}
