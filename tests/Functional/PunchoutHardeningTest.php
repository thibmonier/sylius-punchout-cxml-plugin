<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;

/** Findings of the sprint 1 security review: checkout, placed orders, disabled buyers, headers. */
final class PunchoutHardeningTest extends WebTestCase
{
    use DatabaseTransactionTrait;
    use PunchoutRoundTripTrait;

    public function testTheCheckoutLeadsBackToTheCartDuringAPunchoutSession(): void
    {
        ['buyer' => $buyer, 'secret' => $secret] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();
        $this->startPunchout($buyer, $secret);

        foreach (['/en_US/checkout/', '/en_US/checkout/address', '/en_US/checkout/complete'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/en_US/cart/', null, $path);
        }
    }

    public function testAPlacedOrderIsNeverServedAsThePunchoutCartNorDeleted(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $variant = $catalog->createVariant($this->channel($buyer), 2000);
        $this->startPunchout($buyer, $secret);
        $orderId = $this->addToCurrentCart((string) $variant->getCode(), 1)['id'];

        $order = $this->entityManager()->find(OrderInterface::class, $orderId);
        self::assertInstanceOf(OrderInterface::class, $order);
        $order->setState(OrderInterface::STATE_NEW);
        $this->entityManager()->flush();

        self::assertNotSame($orderId, $this->currentCart()['id']);
        $this->client->request('POST', '/punchout/transfer', ['_csrf_token' => $this->getCsrfToken('tmonier_punchout_transfer')]);

        $this->entityManager()->clear();
        $order = $this->entityManager()->find(OrderInterface::class, $orderId);
        self::assertInstanceOf(OrderInterface::class, $order, 'A placed order is never deleted by the PunchOut session');
        self::assertSame(OrderInterface::STATE_NEW, $order->getState());
    }

    public function testDisablingTheBuyerEndsItsOpenSessions(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $this->startPunchout($buyer, $secret);
        $punchoutCartId = $this->currentCart()['id'];

        $managed = $this->entityManager()->find(Buyer::class, $buyer->getId());
        self::assertInstanceOf(Buyer::class, $managed);
        $managed->setEnabled(false);
        $this->entityManager()->flush();

        self::assertNotSame($punchoutCartId, $this->currentCart()['id']);
        $this->client->request('POST', '/punchout/transfer', ['_csrf_token' => $this->getCsrfToken('tmonier_punchout_transfer')]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testABuyerWithoutChannelCannotOpenASession(): void
    {
        ['buyer' => $buyer, 'secret' => $secret] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();
        $this->client->request('POST', '/punchout/cxml', [], [], ['CONTENT_TYPE' => 'text/xml'], $this->setupRequestXml($buyer, $secret, 'C'));
        if (1 !== preg_match('#/punchout/start/([^<]+)</URL>#', (string) $this->client->getResponse()->getContent(), $matches)) {
            self::fail('No start URL');
        }
        $managed = $this->entityManager()->find(Buyer::class, $buyer->getId());
        self::assertInstanceOf(Buyer::class, $managed);
        $managed->setChannel(null);
        $this->entityManager()->flush();

        $this->client->request('GET', '/punchout/start/' . $matches[1]);

        self::assertResponseStatusCodeSame(403);
        $session = $this->entityManager()->getRepository(PunchoutSession::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(PunchoutSession::class, $session);
        self::assertSame(PunchoutSession::STATUS_CANCELLED, $session->getStatus());
    }

    public function testTheTransferFormMayPostOverHttpOnlyToADevelopmentHost(): void
    {
        ['buyer' => $buyer, 'secret' => $secret] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();
        $this->client->request('POST', '/punchout/cxml', [], [], ['CONTENT_TYPE' => 'text/xml'], $this->setupRequestXml($buyer, $secret, 'C', 'http://localhost/simulator/receive'));
        if (1 !== preg_match('#/punchout/start/([^<]+)</URL>#', (string) $this->client->getResponse()->getContent(), $matches)) {
            self::fail((string) $this->client->getResponse()->getContent());
        }
        $this->client->request('GET', '/punchout/start/' . $matches[1]);
        $this->transfer();

        self::assertStringContainsString('form-action http://localhost', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testTheBuyerEditPageIsNeverCached(): void
    {
        ['buyer' => $buyer] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();
        $admin = $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => 'sylius']);
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->client->request('GET', '/admin/punchout-buyers/' . $buyer->getId() . '/edit');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    private function channel(BuyerInterface $buyer): ChannelInterface
    {
        $channel = $buyer->getChannel();
        self::assertInstanceOf(ChannelInterface::class, $channel);

        return $channel;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
