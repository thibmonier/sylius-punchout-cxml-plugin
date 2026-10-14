<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Wraps each test in a database transaction that is rolled back on tearDown,
 * preventing data leakage between tests.
 *
 * Usage:
 *   - Access the HTTP client via $this->client (created during setUp).
 *   - Do NOT call createClient() again in tests that use this trait.
 *   - The trait creates the client first so the kernel is booted before
 *     getContainer() is called — this satisfies WebTestCase's requirement.
 */
trait DatabaseTransactionTrait
{
    protected static ?EntityManagerInterface $entityManager = null;

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        // createClient() must be called before getContainer() on WebTestCase.
        // We store the client so individual tests can reuse it without re-booting.
        $this->client = self::createClient();

        // Disable kernel reboot between requests so the database connection —
        // and the open transaction — is shared between the test and all HTTP requests.
        $this->client->disableReboot();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::$entityManager = $em instanceof EntityManagerInterface ? $em : null;
        self::$entityManager?->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (
            self::$entityManager instanceof EntityManagerInterface &&
            self::$entityManager->getConnection()->isTransactionActive()
        ) {
            self::$entityManager->getConnection()->rollBack();
        }

        parent::tearDown();
    }
}
