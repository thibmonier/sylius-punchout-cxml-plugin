<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Classification\ProductClassification;
use Tmonier\SyliusPunchoutCxmlPlugin\Classification\TaxonClassification;

/** US-005: UNSPSC code and unit of each returned line. */
final class ClassificationTest extends WebTestCase
{
    use DatabaseTransactionTrait;
    use PunchoutRoundTripTrait;

    public function testTheUnspscCodeIsInheritedFromTheTaxonOrItsParents(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $chairs = $catalog->createTaxon();
        $this->entityManager()->persist(new TaxonClassification($chairs, '56101504'));
        $variant = $catalog->createVariant($this->channel($buyer), 1000, mainTaxon: $catalog->createTaxon($chairs));

        $message = $this->transferred($buyer, $secret, $variant);

        self::assertStringContainsString('<UnitOfMeasure>EA</UnitOfMeasure><Classification domain="UNSPSC">56101504</Classification>', $message);
    }

    public function testTheProductOverridesTheTaxonCodeAndSetsItsUnit(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $chairs = $catalog->createTaxon();
        $this->entityManager()->persist(new TaxonClassification($chairs, '56101504'));
        $variant = $catalog->createVariant($this->channel($buyer), 1000, mainTaxon: $chairs);
        $product = $variant->getProduct();
        self::assertInstanceOf(ProductInterface::class, $product);
        $this->entityManager()->persist(new ProductClassification($product, '56101501', 'BX'));

        $message = $this->transferred($buyer, $secret, $variant);

        self::assertStringContainsString('<UnitOfMeasure>BX</UnitOfMeasure><Classification domain="UNSPSC">56101501</Classification>', $message);
    }

    public function testTheBuyerDefaultsApplyToUnclassifiedProducts(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $managed = $this->entityManager()->find(Buyer::class, $buyer->getId());
        self::assertInstanceOf(Buyer::class, $managed);
        $managed->setDefaultUnit('C62');
        $managed->setDefaultUnspsc('44120000');
        $variant = $catalog->createVariant($this->channel($buyer), 1000);

        $message = $this->transferred($buyer, $secret, $variant);

        self::assertStringContainsString('<UnitOfMeasure>C62</UnitOfMeasure><Classification domain="UNSPSC">44120000</Classification>', $message);
        $this->assertValidAgainstTheDtd($message);
    }

    public function testTheAdminSetsTheCodesOfProductsAndTaxons(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer] = $catalog->createBuyerWithCustomer();
        $taxon = $catalog->createTaxon();
        $variant = $catalog->createVariant($this->channel($buyer), 1000);
        $product = $variant->getProduct();
        self::assertInstanceOf(ProductInterface::class, $product);
        $admin = $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => 'sylius']);
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $productId = $product->getId();
        $taxonId = $taxon->getId();
        self::assertIsInt($productId);
        self::assertIsInt($taxonId);
        $crawler = $this->client->request('GET', sprintf('/admin/products/%d/edit', $productId));
        $form = $crawler->filter('form[name="sylius_admin_product"]')->form();
        $form['sylius_admin_product[tmonierPunchout][unspsc]'] = '12345678';
        $form['sylius_admin_product[tmonierPunchout][unit]'] = 'PK';
        $this->client->submit($form);
        self::assertTrue($this->client->getResponse()->isRedirect(), substr((string) $this->client->getResponse()->getContent(), 0, 300));

        $crawler = $this->client->request('GET', sprintf('/admin/taxons/%d/edit', $taxonId));
        $form = $crawler->filter('form[name="sylius_admin_taxon"]')->form();
        $form['sylius_admin_taxon[tmonierPunchout][unspsc]'] = '87654321';
        $this->client->submit($form);
        self::assertTrue($this->client->getResponse()->isRedirect(), substr((string) $this->client->getResponse()->getContent(), 0, 300));

        $this->entityManager()->clear();
        $productClassification = $this->entityManager()->getRepository(ProductClassification::class)->findOneBy(['product' => $product->getId()]);
        self::assertInstanceOf(ProductClassification::class, $productClassification);
        self::assertSame(['12345678', 'PK'], [$productClassification->getUnspsc(), $productClassification->getUnit()]);
        $taxonClassification = $this->entityManager()->getRepository(TaxonClassification::class)->findOneBy(['taxon' => $taxon->getId()]);
        self::assertInstanceOf(TaxonClassification::class, $taxonClassification);
        self::assertSame('87654321', $taxonClassification->getUnspsc());
    }

    private function transferred(BuyerInterface $buyer, string $secret, ProductVariantInterface $variant): string
    {
        $this->entityManager()->flush();
        $this->startPunchout($buyer, $secret);
        $this->addToCurrentCart((string) $variant->getCode(), 1);

        return $this->transfer();
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
