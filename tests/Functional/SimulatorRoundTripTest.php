<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerIdentity;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;

/**
 * US-026: the buyer simulator plays the procurement system end to end — setup form, start URL,
 * shopping, transfer, reception of the PunchOutOrderMessage validated against the cXML DTD.
 */
final class SimulatorRoundTripTest extends WebTestCase
{
    use DatabaseTransactionTrait;
    use PunchoutRoundTripTrait;

    public function testTheSimulatorReceivesAValidPunchOutOrderMessage(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $channel = $buyer->getChannel();
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $catalog->withoutPromotions($channel);
        $variant = $catalog->createVariant($channel, 4250);

        $this->openSessionFromTheSimulator($buyer, $secret);
        $this->addToCurrentCart((string) $variant->getCode(), 2);
        $this->client->request('POST', '/punchout/transfer', ['_csrf_token' => $this->getCsrfToken('tmonier_punchout_transfer')]);

        $page = $this->receive();
        self::assertStringContainsString('DTD cXML 1.2.071: valid', $page);
        self::assertStringContainsString('<td>' . $variant->getCode() . '</td><td>2</td><td>42.50</td>', $page);
    }

    public function testCancellingSendsAValidMessageWithoutItem(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $channel = $buyer->getChannel();
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $variant = $catalog->createVariant($channel, 1000);

        $this->openSessionFromTheSimulator($buyer, $secret);
        $cartId = $this->addToCurrentCart((string) $variant->getCode(), 1)['id'];
        $this->client->request('POST', '/punchout/cancel', ['_csrf_token' => $this->getCsrfToken('tmonier_punchout_cancel')]);

        $page = $this->receive();
        self::assertStringContainsString('DTD cXML 1.2.071: valid', $page);
        self::assertStringContainsString('No item: the shopper cancelled', $page);
        self::assertNotSame($cartId, $this->currentCart()['id'], 'The PunchOut session is closed');
        $this->client->request('POST', '/punchout/transfer', ['_csrf_token' => $this->getCsrfToken('tmonier_punchout_transfer')]);
        self::assertResponseStatusCodeSame(403);
    }

    private function openSessionFromTheSimulator(BuyerInterface $buyer, string $secret): void
    {
        $identities = [];
        foreach ($buyer->getIdentities() as $identity) {
            $identities[$identity->getRole()] = $identity;
        }
        self::assertArrayHasKey(BuyerIdentity::ROLE_FROM, $identities);
        self::assertArrayHasKey(BuyerIdentity::ROLE_SENDER, $identities);

        $crawler = $this->client->request('GET', '/simulator/cxml');
        $this->client->submit($crawler->selectButton('Send PunchOutSetupRequest →')->form([
            'from_domain' => $identities[BuyerIdentity::ROLE_FROM]->getDomain(),
            'from_identity' => $identities[BuyerIdentity::ROLE_FROM]->getIdentity(),
            'sender_domain' => $identities[BuyerIdentity::ROLE_SENDER]->getDomain(),
            'sender_identity' => $identities[BuyerIdentity::ROLE_SENDER]->getIdentity(),
            'shared_secret' => $secret,
        ]));
        self::assertResponseIsSuccessful();

        $link = $this->client->getCrawler()->selectLink('Click here to enter the shop as a PunchOut buyer →');
        self::assertCount(1, $link, (string) $this->client->getResponse()->getContent());
        $this->client->click($link->link());
        self::assertTrue($this->client->getResponse()->isRedirect(), 'The start URL opens the session and redirects to the shop');
    }

    /** Posts the self-submitting transfer form as the browser would, and returns the simulator page. */
    private function receive(): string
    {
        self::assertResponseIsSuccessful();
        $form = $this->client->getCrawler()->filter('form')->form();
        self::assertStringEndsWith('/simulator/receive', $form->getUri());
        $this->client->submit($form);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }
}
