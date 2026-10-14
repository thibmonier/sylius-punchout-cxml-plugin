<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Log\MessageLog;

/** US-024: exchanges are logged, secrets never, personal data masked. */
final class MessageLogTest extends WebTestCase
{
    use DatabaseTransactionTrait;
    use PunchoutRoundTripTrait;

    public function testTheSetupAndTheReturnedCartAreLoggedAndListed(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $channel = $buyer->getChannel();
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $variant = $catalog->createVariant($channel, 1000);
        $payloadId = 'log-' . bin2hex(random_bytes(6)) . '@buyer.example';
        $setup = str_replace('<BuyerCookie>', '<Extrinsic name="UserEmail">claire.martin@bigcorp.example</Extrinsic><BuyerCookie>', $this->setupRequestXml($buyer, $secret, 'C', payloadId: $payloadId));

        $body = $this->postSetup($setup);
        if (1 !== preg_match('#/punchout/start/([^<]+)</URL>#', $body, $matches)) {
            self::fail($body);
        }
        $this->client->request('GET', '/punchout/start/' . $matches[1]);
        $this->addToCurrentCart((string) $variant->getCode(), 1);
        $this->transfer();

        $in = $this->entityManager()->getRepository(MessageLog::class)->findOneBy(['payloadId' => $payloadId]);
        self::assertInstanceOf(MessageLog::class, $in);
        self::assertSame([MessageLog::DIRECTION_IN, 'PunchOutSetupRequest', 200, $buyer->getId()], [$in->getDirection(), $in->getMessageType(), $in->getStatus(), $in->getBuyer()?->getId()]);
        $out = $this->entityManager()->getRepository(MessageLog::class)->findOneBy(['messageType' => 'PunchOutOrderMessage', 'buyer' => $buyer->getId()]);
        self::assertInstanceOf(MessageLog::class, $out);
        self::assertSame(MessageLog::DIRECTION_OUT, $out->getDirection());

        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/admin/punchout-logs/?criteria[search][value]=' . urlencode($payloadId));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($payloadId, $crawler->filter('table')->text());
        self::assertStringContainsString('PunchOutSetupRequest', $crawler->filter('table')->text());

        $crawler = $this->client->request('GET', sprintf('/admin/punchout-logs/%d', (int) $in->getId()));
        self::assertResponseIsSuccessful();
        $detail = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($secret, $detail, 'The shared secret is never shown');
        self::assertStringNotContainsString('claire.martin@bigcorp.example', $detail, 'E-mail addresses are masked');
        self::assertStringContainsString('c***@bigcorp.example', $crawler->filter('[data-test-tmonier-punchout-log-body]')->text());
    }

    public function testAFailedAuthenticationIsLoggedWithoutBuyer(): void
    {
        ['buyer' => $buyer] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();
        $payloadId = 'fail-' . bin2hex(random_bytes(6)) . '@buyer.example';
        $secret = 'wrong-' . bin2hex(random_bytes(8));

        $this->postSetup($this->setupRequestXml($buyer, $secret, 'C', payloadId: $payloadId));

        $log = $this->entityManager()->getRepository(MessageLog::class)->findOneBy(['payloadId' => $payloadId]);
        self::assertInstanceOf(MessageLog::class, $log);
        self::assertSame([401, null], [$log->getStatus(), $log->getBuyer()]);
        self::assertStringNotContainsString($secret, (string) $log->getMaskedBody());
    }

    private function loginAdmin(): void
    {
        $admin = $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => 'sylius']);
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
