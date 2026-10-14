<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

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

        // The setup rate limiter persists between tests: start each test with a fresh budget.
        $limiter = static::getContainer()->get('limiter.tmonier_punchout_setup');
        if ($limiter instanceof RateLimiterFactoryInterface) {
            foreach (['127.0.0.1', '203.0.113.10'] as $ip) {
                $limiter->create($ip)->reset();
            }
        }

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

    /**
     * Returns a valid CSRF token value for the given intent.
     *
     * The token is generated within a real HTTP request to the test-only
     * /simulator/csrf-token endpoint, so the Symfony session is active when
     * the CSRF manager generates the token. Using the same client (and therefore
     * the same session cookie) ensures the token is valid for the next request.
     */
    protected function getCsrfToken(string $intent): string
    {
        $this->client->request('GET', '/simulator/csrf-token?intent=' . urlencode($intent));
        /** @var array{token?: string}|null $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($data) ? (string) ($data['token'] ?? '') : '';
    }
}
