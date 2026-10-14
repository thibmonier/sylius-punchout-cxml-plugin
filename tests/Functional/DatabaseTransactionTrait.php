<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Wraps each test in a transaction that is rolled back on tearDown,
 * so no test data leaks between tests.
 */
trait DatabaseTransactionTrait
{
    protected static ?EntityManagerInterface $entityManager = null;

    protected function setUp(): void
    {
        parent::setUp();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::$entityManager = $em instanceof EntityManagerInterface ? $em : null;
        self::$entityManager?->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (self::$entityManager instanceof \Doctrine\ORM\EntityManagerInterface && self::$entityManager->getConnection()->isTransactionActive()) {
            self::$entityManager->getConnection()->rollBack();
        }

        parent::tearDown();
    }
}
