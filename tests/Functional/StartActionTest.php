<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\StartTokenIssuer;

final class StartActionTest extends WebTestCase
{
    use DatabaseTransactionTrait;

    public function testStartTokenConsumptionRedirectsToShop(): void
    {
        $client = $this->client;
        $catalog = new TestCatalog(static::getContainer());
        ['buyer' => $buyer] = $catalog->createBuyer();

        $session = new PunchoutSession();
        $session->setBuyer($buyer);
        $session->setOperation('create');
        $session->setBuyerCookie('COOKIE-' . bin2hex(random_bytes(8)));
        $session->setReturnUrl('https://ariba.example.com/return');
        $session->setExtrinsics([]);
        $session->setItemsOut([]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist($session);
        $em->flush();

        $issuer = static::getContainer()->get(StartTokenIssuer::class);
        self::assertInstanceOf(StartTokenIssuer::class, $issuer);
        $token = $issuer->issue($session, 300);

        $client->request('GET', '/punchout/start/' . $token);

        $response = $client->getResponse();
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/', $response->headers->get('Location') ?? '');
    }

    public function testUnknownTokenReturns404(): void
    {
        $client = $this->client;

        $client->request('GET', '/punchout/start/' . bin2hex(random_bytes(32)));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testHeadRequestDoesNotConsumeToken(): void
    {
        $client = $this->client;
        $catalog = new TestCatalog(static::getContainer());
        ['buyer' => $buyer] = $catalog->createBuyer();

        $session = new PunchoutSession();
        $session->setBuyer($buyer);
        $session->setOperation('create');
        $session->setBuyerCookie('COOKIE-' . bin2hex(random_bytes(8)));
        $session->setReturnUrl('https://ariba.example.com/return');
        $session->setExtrinsics([]);
        $session->setItemsOut([]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist($session);
        $em->flush();

        $issuer = static::getContainer()->get(StartTokenIssuer::class);
        self::assertInstanceOf(StartTokenIssuer::class, $issuer);
        $token = $issuer->issue($session, 300);

        // HEAD should not consume the token
        $client->request('HEAD', '/punchout/start/' . $token);
        self::assertSame(200, $client->getResponse()->getStatusCode());

        // GET should still work (token not consumed by HEAD)
        $client->request('GET', '/punchout/start/' . $token);
        self::assertSame(302, $client->getResponse()->getStatusCode());
    }

    /**
     * US-013 security: a start token can only be used once.
     * A second GET on the same token must return 404.
     */
    public function testConsumedTokenCannotBeReused(): void
    {
        $client = $this->client;
        $catalog = new TestCatalog(static::getContainer());
        ['buyer' => $buyer] = $catalog->createBuyer();

        $session = new PunchoutSession();
        $session->setBuyer($buyer);
        $session->setOperation('create');
        $session->setBuyerCookie('COOKIE-' . bin2hex(random_bytes(8)));
        $session->setReturnUrl('https://ariba.example.com/return');
        $session->setExtrinsics([]);
        $session->setItemsOut([]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist($session);
        $em->flush();

        $issuer = static::getContainer()->get(StartTokenIssuer::class);
        self::assertInstanceOf(StartTokenIssuer::class, $issuer);
        $token = $issuer->issue($session, 300);

        // First GET consumes the token → 302
        $client->request('GET', '/punchout/start/' . $token);
        self::assertSame(302, $client->getResponse()->getStatusCode());

        // Second GET on the same token → 404 (already consumed)
        $client->request('GET', '/punchout/start/' . $token);
        self::assertSame(404, $client->getResponse()->getStatusCode(), 'Consumed token must return 404');
    }

    /**
     * US-013: an expired token (TTL = 0 seconds in the past) must return 404.
     */
    public function testExpiredTokenReturns404(): void
    {
        $client = $this->client;
        $catalog = new TestCatalog(static::getContainer());
        ['buyer' => $buyer] = $catalog->createBuyer();

        $session = new PunchoutSession();
        $session->setBuyer($buyer);
        $session->setOperation('create');
        $session->setBuyerCookie('COOKIE-' . bin2hex(random_bytes(8)));
        $session->setReturnUrl('https://ariba.example.com/return');
        $session->setExtrinsics([]);
        $session->setItemsOut([]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist($session);
        $em->flush();

        // Issue a token with TTL = 0 seconds → immediately expired
        $issuer = static::getContainer()->get(StartTokenIssuer::class);
        self::assertInstanceOf(StartTokenIssuer::class, $issuer);
        $token = $issuer->issue($session, 0);

        // Token is already expired → 404
        $client->request('GET', '/punchout/start/' . $token);
        self::assertSame(404, $client->getResponse()->getStatusCode(), 'Expired token must return 404');
    }
}
