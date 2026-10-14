<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;

/**
 * US-013 / US-018: the PunchOut cart is isolated from the customer's regular cart, priced and
 * taxed by Sylius, and the transfer never touches the regular cart.
 */
final class PunchoutCartIsolationTest extends WebTestCase
{
    use DatabaseTransactionTrait;
    use PunchoutRoundTripTrait;

    public function testThePunchoutCartIsIsolatedFromTheRegularCartAndTransferred(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $channel = $this->channel($buyer);
        $catalog->withoutPromotions($channel);
        $regularVariant = $catalog->createVariant($channel, 5000);
        $regularCart = $catalog->createRegularCart($buyer, $regularVariant);
        $punchoutVariant = $catalog->createVariant($channel, 25440);

        $this->startPunchout($buyer, $secret, 'COOKIE-ISOLATION');

        $cart = $this->currentCart();
        self::assertNotSame($regularCart->getId(), $cart['id'], 'The current cart is the PunchOut cart, not the regular one');
        self::assertSame([], $cart['items'], 'The PunchOut cart starts empty');

        $cart = $this->addToCurrentCart((string) $punchoutVariant->getCode(), 3);
        self::assertNotSame($regularCart->getId(), $cart['id']);
        self::assertSame([['variant' => $punchoutVariant->getCode(), 'quantity' => 3]], $cart['items']);

        $message = $this->transfer();
        self::assertStringContainsString('<BuyerCookie>COOKIE-ISOLATION</BuyerCookie>', $message);
        self::assertStringContainsString('<SupplierPartID>' . $punchoutVariant->getCode() . '</SupplierPartID>', $message);
        self::assertStringContainsString('quantity="3"', $message);
        self::assertMatchesRegularExpression('#<UnitPrice><Money currency="[A-Z]{3}">254\.40</Money></UnitPrice>#', $message);
        self::assertMatchesRegularExpression('#<Total><Money currency="[A-Z]{3}">763\.20</Money></Total>#', $message);
        self::assertStringNotContainsString($regularVariant->getCode() . '<', $message, 'Regular cart lines are never transferred');

        $regular = $this->entityManager()->find(OrderInterface::class, $regularCart->getId());
        self::assertInstanceOf(OrderInterface::class, $regular);
        self::assertSame(OrderInterface::STATE_CART, $regular->getState());
        self::assertCount(1, $regular->getItems(), 'The regular cart is untouched by the transfer');
        self::assertSame(2, $regular->getItems()->first() ? $regular->getItems()->first()->getQuantity() : 0);

        self::assertNotSame($cart['id'], $this->currentCart()['id'], 'After the transfer the PunchOut cart is no longer the current cart');
    }

    public function testATaxIncludedRateIsRemovedFromTheTransferredPrice(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $channel = $this->channel($buyer);
        $catalog->withoutPromotions($channel);
        $category = $catalog->createTaxCategoryWithRate($channel, 0.2, includedInPrice: true);
        $variant = $catalog->createVariant($channel, 12000, $category);

        $this->startPunchout($buyer, $secret);
        $this->addToCurrentCart((string) $variant->getCode(), 1);
        $message = $this->transfer();

        self::assertMatchesRegularExpression('#<UnitPrice><Money currency="[A-Z]{3}">100\.00</Money></UnitPrice>#', $message);
        self::assertMatchesRegularExpression('#<Total><Money currency="[A-Z]{3}">100\.00</Money></Total>#', $message);
    }

    public function testATaxAddedOnTopKeepsTheCatalogPrice(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $channel = $this->channel($buyer);
        $catalog->withoutPromotions($channel);
        $category = $catalog->createTaxCategoryWithRate($channel, 0.2, includedInPrice: false);
        $variant = $catalog->createVariant($channel, 10000, $category);

        $this->startPunchout($buyer, $secret);
        $this->addToCurrentCart((string) $variant->getCode(), 2);
        $message = $this->transfer();

        self::assertMatchesRegularExpression('#<UnitPrice><Money currency="[A-Z]{3}">100\.00</Money></UnitPrice>#', $message);
        self::assertMatchesRegularExpression('#<Total><Money currency="[A-Z]{3}">200\.00</Money></Total>#', $message);
    }

    public function testAnotherCustomerLoggedInTheSameBrowserNeverGetsThePunchoutCart(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $this->startPunchout($buyer, $secret);
        $punchoutCartId = $this->currentCart()['id'];

        $other = $this->entityManager()->getRepository(ShopUserInterface::class)->createQueryBuilder('u')
            ->andWhere('u.customer != :customer')->setParameter('customer', $buyer->getCustomer())
            ->andWhere('u.enabled = true')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        self::assertInstanceOf(ShopUserInterface::class, $other);
        $this->client->request('GET', '/en_US/logout');
        $this->client->loginUser($other, 'shop');

        self::assertNotSame($punchoutCartId, $this->currentCart()['id']);
        $this->client->request('POST', '/punchout/transfer', ['_csrf_token' => $this->getCsrfToken('tmonier_punchout_transfer')]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCancellingWithoutAValidCsrfTokenIsRefused(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $this->startPunchout($buyer, $secret);
        $punchoutCartId = $this->currentCart()['id'];

        $this->client->request('POST', '/punchout/cancel', ['_csrf_token' => 'invalid']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($punchoutCartId, $this->currentCart()['id'], 'The session is still open');
    }

    public function testTheTransferPageIsNotCachedAndOnlyPostsToTheReturnUrlOrigin(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $variant = $catalog->createVariant($this->channel($buyer), 1000);
        $this->startPunchout($buyer, $secret);
        $this->addToCurrentCart((string) $variant->getCode(), 1);
        $this->transfer();

        $headers = $this->client->getResponse()->headers;
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        $csp = (string) $headers->get('Content-Security-Policy');
        self::assertStringContainsString('form-action https://ariba.example.com', $csp);
        self::assertMatchesRegularExpression("/script-src 'nonce-[A-Za-z0-9+\\/=]+'/", $csp);
        self::assertStringContainsString('target="_top"', (string) $this->client->getResponse()->getContent());
    }

    public function testADisabledChannelRefusesTheSession(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $this->client->request('POST', '/punchout/cxml', [], [], ['CONTENT_TYPE' => 'text/xml'], $this->setupRequestXml($buyer, $secret, 'C'));
        if (1 !== preg_match('#/punchout/start/([^<]+)</URL>#', (string) $this->client->getResponse()->getContent(), $matches)) {
            self::fail('No start URL');
        }

        $catalog->managed($this->channel($buyer))->setEnabled(false);
        $this->entityManager()->flush();

        $this->client->request('GET', '/punchout/start/' . $matches[1]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnOversizedSetupRequestIsRefused(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $xml = str_replace('</cXML>', '<!--' . str_repeat('x', 300 * 1024) . '--></cXML>', $this->setupRequestXml($buyer, $secret, 'C'));

        $this->client->request('POST', '/punchout/cxml', [], [], ['CONTENT_TYPE' => 'text/xml'], $xml);

        self::assertStringContainsString('<Status code="406"', (string) $this->client->getResponse()->getContent());
    }

    public function testTheCartPageOffersTheTransferOnlyInAPunchoutSession(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $variant = $catalog->createVariant($this->channel($buyer), 1500);
        $customer = $buyer->getCustomer();
        self::assertNotNull($customer);
        $user = $customer->getUser();
        self::assertInstanceOf(ShopUserInterface::class, $user);

        $this->client->loginUser($user, 'shop');
        $crawler = $this->client->request('GET', '/en_US/cart/');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-test-tmonier-punchout-banner]'), 'No banner outside a PunchOut session');

        $this->startPunchout($buyer, $secret);
        $this->addToCurrentCart((string) $variant->getCode(), 1);
        $crawler = $this->client->request('GET', '/en_US/cart/');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-test-tmonier-punchout-banner]'));
        self::assertStringContainsString((string) $buyer->getName(), $crawler->filter('[data-test-tmonier-punchout-banner]')->text());
        self::assertCount(0, $crawler->filter('[data-test-cart-checkout-button]'), 'No Sylius checkout button in a PunchOut session');
        self::assertCount(0, $crawler->filter('a[href$="/checkout/"]'), 'The side cart offers no checkout link');
        self::assertCount(1, $crawler->filter('[data-test-tmonier-punchout-offcanvas-transfer]'), 'The side cart offers the transfer');

        $this->client->submit($crawler->filter('[data-test-tmonier-punchout-transfer]')->form());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('name="cXML-base64"', (string) $this->client->getResponse()->getContent());
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
