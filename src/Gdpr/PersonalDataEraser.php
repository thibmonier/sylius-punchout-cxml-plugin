<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Gdpr;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Tmonier\SyliusPunchoutCxmlPlugin\Log\BodyMasker;

/**
 * Erasure (US-025) of the PunchOut data of a person, by e-mail address: the Extrinsic values of the
 * sessions of the buyers logged in as this Sylius customer or naming the address, and the bodies of
 * their journal entries (and of any entry showing the address in its masked form). The rows, their
 * dates, types and statuses are kept: the statistics stay, nothing names the person any more. The
 * Sylius customer itself is erased by the shop, not by this plugin.
 */
final readonly class PersonalDataEraser
{
    private const LOG = 'tmonier_punchout_message_log';

    private const SESSION = 'tmonier_punchout_session';

    private const BATCH = 500;

    public function __construct(
        private Connection $connection,
        private BodyMasker $masker,
    ) {
    }

    /** @return array{sessions: int, logs: int} the rows erased, or that would be with $dryRun */
    public function erase(string $email, bool $dryRun = false): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            throw new \InvalidArgumentException('An e-mail address is expected.');
        }
        $buyerIds = $this->ids($this->connection->fetchFirstColumn(
            'SELECT b.id FROM tmonier_punchout_buyer b INNER JOIN sylius_customer c ON c.id = b.customer_id WHERE c.email_canonical = :email',
            ['email' => $email],
        ));
        $sessionIds = $this->sessionsNaming($email, $buyerIds);
        $logIds = $this->logsToErase($email, $buyerIds, $sessionIds);

        if (!$dryRun) {
            $this->connection->transactional(function (Connection $connection) use ($sessionIds, $logIds): void {
                foreach (array_chunk($sessionIds, self::BATCH) as $ids) {
                    $connection->executeStatement('UPDATE ' . self::SESSION . " SET extrinsics = '[]' WHERE id IN (:ids)", ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER]);
                }
                foreach (array_chunk($logIds, self::BATCH) as $ids) {
                    $connection->executeStatement('UPDATE ' . self::LOG . ' SET masked_body = NULL WHERE id IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER]);
                }
            });
        }

        return ['sessions' => count($sessionIds), 'logs' => count($logIds)];
    }

    /**
     * Sessions with Extrinsic values of the buyers of the customer, or naming the address (JSON read in
     * PHP: the JSON functions differ between MySQL, MariaDB and PostgreSQL).
     *
     * @param list<int> $buyerIds
     *
     * @return list<int>
     */
    private function sessionsNaming(string $email, array $buyerIds): array
    {
        $ids = [];
        $lastId = 0;
        do {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, buyer_id, extrinsics FROM ' . self::SESSION . ' WHERE id > :last ORDER BY id LIMIT ' . self::BATCH,
                ['last' => $lastId],
            );
            foreach ($rows as $row) {
                $lastId = $this->int($row['id']);
                $extrinsics = is_string($row['extrinsics']) ? json_decode($row['extrinsics'], true) : null;
                if (!is_array($extrinsics) || $extrinsics === []) {
                    continue;
                }
                $values = array_map(static fn (mixed $value): string => is_string($value) ? mb_strtolower(trim($value)) : '', $extrinsics);
                if (in_array($this->int($row['buyer_id']), $buyerIds, true) || in_array($email, $values, true)) {
                    $ids[] = $lastId;
                }
            }
        } while (count($rows) === self::BATCH);

        return $ids;
    }

    /**
     * @param list<int> $buyerIds
     * @param list<int> $sessionIds
     *
     * @return list<int>
     */
    private function logsToErase(string $email, array $buyerIds, array $sessionIds): array
    {
        $masked = $this->masker->maskText($email);
        $sql = 'SELECT id FROM ' . self::LOG . ' WHERE masked_body IS NOT NULL AND (LOWER(masked_body) LIKE :masked';
        $parameters = ['masked' => '%' . addcslashes($masked, '%_\\') . '%'];
        $types = [];
        foreach (['buyer_id' => $buyerIds, 'session_id' => $sessionIds] as $column => $ids) {
            if ($ids !== []) {
                $sql .= sprintf(' OR %s IN (:%s)', $column, $column);
                $parameters[$column] = $ids;
                $types[$column] = ArrayParameterType::INTEGER;
            }
        }

        return $this->ids($this->connection->fetchFirstColumn($sql . ')', $parameters, $types));
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<int>
     */
    private function ids(array $values): array
    {
        return array_map($this->int(...), $values);
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
