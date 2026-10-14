<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;

/** US-022 (edit: the requisition is rebuilt as a cart) and US-023 (inspect: read only). */
final class PunchoutEditInspectTest extends WebTestCase
{
    use DatabaseTransactionTrait;
    use PunchoutRoundTripTrait;

    public function testEditRebuildsTheCartFromTheItemsOfTheRequisition(): void
    {
        [$buyer, $secret, $a, $b] = $this->buyerAndTwoVariants();

        $this->open($buyer, $secret, 'edit', [[$a, 2, true], [$b, 1, false]]);
        self::assertResponseRedirects('/en_US/cart/');

        $cart = $this->currentCart();
        self::assertEqualsCanonicalizing(
            [['variant' => $a->getCode(), 'quantity' => 2], ['variant' => $b->getCode(), 'quantity' => 1]],
            $cart['items'],
        );

        $message = $this->transfer();
        self::assertStringContainsString('operationAllowed="edit"', $message);
        self::assertStringContainsString('<ItemIn quantity="2"><ItemID><SupplierPartID>' . $a->getCode() . '</SupplierPartID>', $message);
        self::assertStringContainsString('<ItemIn quantity="1"><ItemID><SupplierPartID>' . $b->getCode() . '</SupplierPartID>', $message);
    }

    public function testAnItemNoLongerSoldIsLeftOutAndReported(): void
    {
        [$buyer, $secret, $a, $b] = $this->buyerAndTwoVariants();
        $b->setEnabled(false);
        $this->entityManager()->flush();

        $this->open($buyer, $secret, 'edit', [[$a, 3, true], [$b, 1, true]]);
        $crawler = $this->client->followRedirect();

        self::assertSame([['variant' => $a->getCode(), 'quantity' => 3]], $this->currentCart()['items']);
        self::assertStringContainsString((string) $b->getCode(), $crawler->filter('[data-test-tmonier-punchout-unavailable]')->text());
    }

    public function testInspectShowsTheRequisitionReadOnlyAndGoesBackWithoutItems(): void
    {
        [$buyer, $secret, $a] = $this->buyerAndTwoVariants();

        $this->open($buyer, $secret, 'inspect', [[$a, 4, true]]);
        self::assertResponseRedirects('/en_US/punchout/inspect');
        $crawler = $this->client->followRedirect();

        $row = $crawler->filter('[data-test-tmonier-punchout-inspect-item]');
        self::assertCount(1, $row);
        self::assertStringContainsString((string) $a->getCode(), $row->text());
        self::assertStringContainsString('4', $row->text());
        self::assertCount(0, $crawler->filter('form[action*="/cart"]'), 'No cart form on the inspect page');

        $cartPage = $this->client->request('GET', '/en_US/cart/');
        self::assertCount(0, $cartPage->filter('[data-test-tmonier-punchout-transfer], [data-test-tmonier-punchout-offcanvas-transfer]'), 'Nothing to transfer while inspecting');
        $this->client->request('POST', '/simulator/test/cart/add', ['variant' => (string) $a->getCode(), 'quantity' => '1']);
        $this->client->submit($this->client->request('GET', '/en_US/punchout/inspect')->filter('[data-test-tmonier-punchout-back]')->form());
        $message = (string) preg_replace('/>\s+</', '><', (string) base64_decode((string) $this->client->getCrawler()->filter('input[name="cXML-base64"]')->attr('value'), true));

        self::assertStringContainsString('operationAllowed="inspect"', $message);
        self::assertStringNotContainsString('<ItemIn', $message, 'Inspecting never sends items back');
        $this->assertValidAgainstTheDtd($message);
    }

    public function testARequisitionWithTooManyItemsIsRefused(): void
    {
        [$buyer, $secret, $a] = $this->buyerAndTwoVariants();
        $items = str_repeat('<ItemOut quantity="1"><ItemID><SupplierPartID>' . $a->getCode() . '</SupplierPartID></ItemID></ItemOut>', 501);
        $xml = str_replace('</BrowserFormPost>', '</BrowserFormPost>' . $items, $this->setupRequestXml($buyer, $secret, 'C', operation: 'edit'));

        self::assertStringContainsString('<Status code="400"', $this->postSetup($xml));
    }

    public function testRepeatedItemsOfOneVariantAreMergedAndCapped(): void
    {
        [$buyer, $secret, $a] = $this->buyerAndTwoVariants();

        $this->open($buyer, $secret, 'edit', [[$a, 9999, true], [$a, 9999, true], [$a, 2, false]]);

        self::assertSame([['variant' => $a->getCode(), 'quantity' => 9999]], $this->currentCart()['items']);
    }

    public function testInspectNamesOnlyItemsSoldOnTheChannel(): void
    {
        [$buyer, $secret, $a, $b] = $this->buyerAndTwoVariants();
        $b->setEnabled(false);
        $this->entityManager()->flush();

        $this->open($buyer, $secret, 'inspect', [[$a, 1, true], [$b, 1, true]]);
        $crawler = $this->client->followRedirect();

        $rows = $crawler->filter('[data-test-tmonier-punchout-inspect-item]');
        self::assertStringContainsString((string) $a->getProduct()?->getName(), $rows->eq(0)->text());
        self::assertStringNotContainsString((string) $b->getProduct()?->getName(), $rows->eq(1)->text());
    }

    /** @return array{BuyerInterface, string, ProductVariantInterface, ProductVariantInterface} */
    private function buyerAndTwoVariants(): array
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $channel = $buyer->getChannel();
        self::assertInstanceOf(ChannelInterface::class, $channel);

        return [$buyer, $secret, $catalog->createVariant($channel, 1500), $catalog->createVariant($channel, 2500)];
    }

    /** @param list<array{ProductVariantInterface, int, bool}> $items variant, quantity, with SupplierPartAuxiliaryID */
    private function open(BuyerInterface $buyer, string $secret, string $operation, array $items): void
    {
        $itemsOut = '';
        foreach ($items as [$variant, $quantity, $withAux]) {
            $aux = $withAux ? '<SupplierPartAuxiliaryID>v1:' . $variant->getCode() . '</SupplierPartAuxiliaryID>' : '';
            $itemsOut .= '<ItemOut quantity="' . $quantity . '"><ItemID><SupplierPartID>' . $variant->getCode() . '</SupplierPartID>' . $aux . '</ItemID></ItemOut>';
        }
        $xml = str_replace('</BrowserFormPost>', '</BrowserFormPost>' . $itemsOut, $this->setupRequestXml($buyer, $secret, 'C', operation: $operation));
        $body = $this->postSetup($xml);
        if (1 !== preg_match('#/punchout/start/([^<]+)</URL>#', $body, $matches)) {
            self::fail($body);
        }
        $this->client->request('GET', '/punchout/start/' . $matches[1]);
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
