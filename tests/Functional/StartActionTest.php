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
}
