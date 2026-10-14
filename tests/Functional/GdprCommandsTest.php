<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;

/** US-025: retention purge and erasure of the PunchOut personal data. */
final class GdprCommandsTest extends WebTestCase
{
    use DatabaseTransactionTrait;

    public function testThePurgeDeletesWhatIsOlderThanTheRetention(): void
    {
        ['buyer' => $buyer] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();
        $old = $this->session($buyer, [], '-120 days');
        $recent = $this->session($buyer, [], '-10 days');
        $oldLog = $this->log($buyer, null, 'x', '-120 days');
        $recentLog = $this->log($buyer, null, 'x', '-10 days');

        $dryRun = $this->executeCommand('tmonier:punchout:purge', ['--dry-run' => true, '--days' => '90']);
        self::assertStringContainsString('Would delete', $dryRun);
        self::assertTrue($this->exists('tmonier_punchout_session', $old), 'A dry run deletes nothing');

        $this->executeCommand('tmonier:punchout:purge', ['--days' => '90']);
        self::assertFalse($this->exists('tmonier_punchout_session', $old));
        self::assertFalse($this->exists('tmonier_punchout_message_log', $oldLog));
        self::assertTrue($this->exists('tmonier_punchout_session', $recent));
        self::assertTrue($this->exists('tmonier_punchout_message_log', $recentLog));
    }

    public function testErasingAPersonKeepsTheStatisticsOnly(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer] = $catalog->createBuyerWithCustomer();
        $named = $this->session($buyer, ['UserEmail' => 'Claire.Martin@bigcorp.example', 'CostCenter' => 'CC-42'], '-1 day');
        $other = $this->session($buyer, ['UserEmail' => 'paul.durand@bigcorp.example'], '-1 day');
        $namedLog = $this->log($buyer, $named, '<Extrinsic name="CostCenter">CC-42</Extrinsic>', '-1 day');
        $setupLog = $this->log($buyer, null, '<Extrinsic name="UserEmail">c***@bigcorp.example</Extrinsic>', '-1 day');
        $otherLog = $this->log($buyer, $other, '<Extrinsic name="UserEmail">p***@bigcorp.example</Extrinsic>', '-1 day');

        $output = $this->executeCommand('tmonier:punchout:erase', ['email' => 'claire.martin@bigcorp.example']);

        self::assertStringContainsString('Erased the personal data of 1 sessions and 2 journal entries', $output);
        self::assertSame('[]', $this->value('tmonier_punchout_session', 'extrinsics', $named));
        self::assertNull($this->value('tmonier_punchout_message_log', 'masked_body', $namedLog));
        self::assertNull($this->value('tmonier_punchout_message_log', 'masked_body', $setupLog));
        self::assertTrue($this->exists('tmonier_punchout_message_log', $namedLog), 'The statistics stay');
        $extrinsics = $this->value('tmonier_punchout_session', 'extrinsics', $other);
        self::assertIsString($extrinsics);
        self::assertStringContainsString('paul.durand', $extrinsics);
        self::assertNotNull($this->value('tmonier_punchout_message_log', 'masked_body', $otherLog));
    }

    public function testAnInvalidAddressIsRefused(): void
    {
        $tester = $this->tester('tmonier:punchout:erase');
        $tester->execute(['email' => 'not-an-address']);

        self::assertSame(2, $tester->getStatusCode());
    }

    /** @param array<string, string> $extrinsics */
    private function session(BuyerInterface $buyer, array $extrinsics, string $age): int
    {
        $date = new \DateTimeImmutable($age);
        $this->connection()->insert('tmonier_punchout_session', [
            'buyer_id' => $buyer->getId(), 'operation' => 'create', 'buyer_cookie' => 'c', 'return_url' => 'https://ariba.example.com/return',
            'status' => 'transferred', 'extrinsics' => json_encode($extrinsics), 'items_out' => '[]', 'from_domain' => 'd', 'from_identity' => 'i',
            'created_at' => $date, 'updated_at' => $date,
        ], ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);

        return (int) $this->connection()->lastInsertId();
    }

    private function log(BuyerInterface $buyer, ?int $session, string $body, string $age): int
    {
        $this->connection()->insert('tmonier_punchout_message_log', [
            'buyer_id' => $buyer->getId(), 'session_id' => $session, 'direction' => 'in', 'protocol' => 'cxml', 'message_type' => 'PunchOutSetupRequest',
            'payload_id' => 'p', 'status' => 200, 'body_hash' => str_repeat('0', 64), 'masked_body' => $body, 'created_at' => new \DateTimeImmutable($age),
        ], ['created_at' => Types::DATETIME_IMMUTABLE]);

        return (int) $this->connection()->lastInsertId();
    }

    private function exists(string $table, int $id): bool
    {
        return false !== $this->connection()->fetchOne(sprintf('SELECT id FROM %s WHERE id = ?', $table), [$id]);
    }

    private function value(string $table, string $column, int $id): mixed
    {
        return $this->connection()->fetchOne(sprintf('SELECT %s FROM %s WHERE id = ?', $column, $table), [$id]);
    }

    /** @param array<string, mixed> $input */
    private function executeCommand(string $command, array $input): string
    {
        $tester = $this->tester($command);
        $tester->execute($input);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        return preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
    }

    private function tester(string $command): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find($command));
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
