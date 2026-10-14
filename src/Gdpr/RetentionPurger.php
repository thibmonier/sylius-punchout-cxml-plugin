<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Gdpr;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Retention (US-025): the journal entries and the PunchOut sessions older than the retention are
 * deleted (a session by its last change). Kept PunchOut carts (`keep_transferred_carts`) stay Sylius
 * carts: `sylius:remove-expired-carts` removes them.
 */
final readonly class RetentionPurger
{
    private const LOG = 'tmonier_punchout_message_log';

    private const SESSION = 'tmonier_punchout_session';

    public function __construct(private Connection $connection)
    {
    }

    /** @return array{logs: int, sessions: int} the rows deleted, or that would be with $dryRun */
    public function purge(\DateTimeImmutable $before, bool $dryRun = false): array
    {
        $counts = [
            'logs' => $this->count(self::LOG, 'created_at', $before),
            'sessions' => $this->count(self::SESSION, 'updated_at', $before),
        ];
        if (!$dryRun) {
            $this->connection->transactional(function (Connection $connection) use ($before): void {
                $connection->executeStatement('DELETE FROM ' . self::LOG . ' WHERE created_at < :before', ['before' => $before], ['before' => Types::DATETIME_IMMUTABLE]);
                $connection->executeStatement('DELETE FROM ' . self::SESSION . ' WHERE updated_at < :before', ['before' => $before], ['before' => Types::DATETIME_IMMUTABLE]);
            });
        }

        return $counts;
    }

    private function count(string $table, string $column, \DateTimeImmutable $before): int
    {
        $count = $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE %s < :before', $table, $column),
            ['before' => $before],
            ['before' => Types::DATETIME_IMMUTABLE],
        );

        return is_numeric($count) ? (int) $count : 0;
    }
}
