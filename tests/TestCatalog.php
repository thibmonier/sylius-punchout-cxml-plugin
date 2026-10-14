<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxRateInterface;
use Sylius\Component\Taxation\Model\TaxCategoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerCredential;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerIdentity;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\CredentialGenerator;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\CredentialHasher;

/**
 * Creates the minimal test data needed by functional and integration tests.
 *
 * Secrets are generated at runtime. NEVER use hard-coded credentials.
 */
final readonly class TestCatalog
{
    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * Creates a PunchOut buyer with identities and a hashed credential.
     * No customer or channel linked.
     *
     * @param list<string> $returnDomains
     * @param list<string> $iframeOrigins
     *
     * @return array{buyer: Buyer, secret: string}
     */
    public function createBuyer(
        string $fromDomain = 'AribaNetworkUserId',
        string $fromIdentity = 'test-buyer@example.com',
        string $senderDomain = 'AribaNetworkUserId',
        string $senderIdentity = 'test-system@example.com',
        array $returnDomains = ['ariba.example.com', 'localhost'],
        array $iframeOrigins = [],
    ): array {
        $em = $this->container->get(EntityManagerInterface::class);
        $hasher = $this->container->get(CredentialHasher::class);
        $generator = $this->container->get(CredentialGenerator::class);

        // Generate a unique secret at runtime
        $plainSecret = $generator->generate();

        $buyer = new Buyer();
        $buyer->setCode('test-buyer-' . bin2hex(random_bytes(8)));
        $buyer->setName('Test Buyer ' . bin2hex(random_bytes(4)));
        $buyer->setProtocol('cxml');
        $buyer->setEnabled(true);
        $buyer->setReturnDomains($returnDomains);
        $buyer->setIframeOrigins($iframeOrigins);

        $from = new BuyerIdentity();
        $from->setRole(BuyerIdentity::ROLE_FROM);
        $from->setDomain($fromDomain);
        $from->setIdentity($fromIdentity);
        $from->setBuyer($buyer);
        $buyer->addIdentity($from);

        $sender = new BuyerIdentity();
        $sender->setRole(BuyerIdentity::ROLE_SENDER);
        $sender->setDomain($senderDomain);
        $sender->setIdentity($senderIdentity);
        $sender->setBuyer($buyer);
        $buyer->addIdentity($sender);

        $credential = new BuyerCredential();
        $credential->setPasswordHash($hasher->hash($plainSecret));
        $credential->setBuyer($buyer);
        $buyer->addCredential($credential);

        $em->persist($buyer);
        $em->flush();

        return ['buyer' => $buyer, 'secret' => $plainSecret];
    }

    /**
     * Creates a buyer linked to the default fixture customer (shop@example.com)
     * and the default fixture channel (FASHION_WEB), ready for full E2E tests.
     *
     * @param list<string> $returnDomains
     * @param list<string> $iframeOrigins
     *
     * @return array{buyer: Buyer, secret: string}
     */
    public function createBuyerWithCustomer(
        string $fromDomain = 'AribaNetworkUserId',
        string $fromIdentity = 'test-buyer-e2e@example.com',
        string $senderDomain = 'AribaNetworkUserId',
        string $senderIdentity = 'test-system-e2e@example.com',
        array $returnDomains = ['ariba.example.com', 'localhost'],
        array $iframeOrigins = [],
    ): array {
        ['buyer' => $buyer, 'secret' => $secret] = $this->createBuyer(
            $fromDomain,
            $fromIdentity,
            $senderDomain,
            $senderIdentity,
            $returnDomains,
            $iframeOrigins,
        );

        $em = $this->container->get(EntityManagerInterface::class);

        // Link to the default Sylius channel from fixtures
        $channel = $this->getDefaultChannel();
        if ($channel instanceof ChannelInterface) {
            $buyer->setChannel($channel);
        }

        // Link to the default Sylius shop customer from fixtures
        $customer = $this->getDefaultShopCustomer();
        if ($customer instanceof CustomerInterface) {
            $buyer->setCustomer($customer);

            // R7: ensure the shop user is verified so the happy path works.
            // The channel may require account verification (account_verification_required=1),
            // but the default fixture user shop@example.com has verifiedAt=null.
            // Tests that test the unverified scenario must explicitly unset verifiedAt.
            $shopUser = $customer->getUser();
            if ($shopUser instanceof \Sylius\Component\User\Model\UserInterface && method_exists($shopUser, 'setVerifiedAt') && !$shopUser->isVerified()) {
                $shopUser->setVerifiedAt(new \DateTime());
            }
        }

        $em->flush();

        return ['buyer' => $buyer, 'secret' => $secret];
    }

    /**
     * Creates a buyer for a TTC (tax-inclusive) channel test.
     * R5: taxIncluded flag removed — neutral tax adjustments are auto-detected.
     * Use with createCartWithTaxItem() which creates neutral adjustments.
     *
     * @return array{buyer: Buyer, secret: string}
     */

    /**
     * Creates an enabled product with one untracked variant sold on the channel at $priceCentimes.
     * The variant gets $taxCategory when given, so the Sylius taxation processor applies its rates.
     */
    public function createVariant(
        ChannelInterface $channel,
        int $priceCentimes,
        ?TaxCategoryInterface $taxCategory = null,
    ): ProductVariantInterface {
        $em = $this->container->get(EntityManagerInterface::class);
        $channel = $this->managed($channel);
        $code = 'PO_TEST_' . strtoupper(bin2hex(random_bytes(5)));
        $locale = $channel->getDefaultLocale()?->getCode() ?? 'en_US';

        /** @var ProductInterface $product */
        $product = $this->container->get('sylius.factory.product')->createWithVariant();
        $product->setCode($code);
        $product->setCurrentLocale($locale);
        $product->setFallbackLocale($locale);
        $product->setName('PunchOut test product ' . $code);
        $product->setSlug(strtolower($code));
        $product->addChannel($channel);

        $variant = $product->getVariants()->first();
        $this->assertVariant($variant);
        $variant->setCode($code . '_V');
        $variant->setCurrentLocale($locale);
        $variant->setFallbackLocale($locale);
        $variant->setName('PunchOut test product ' . $code);
        $variant->setTracked(false);
        $variant->setTaxCategory($taxCategory);

        /** @var ChannelPricingInterface $pricing */
        $pricing = $this->container->get('sylius.factory.channel_pricing')->createNew();
        $pricing->setChannelCode((string) $channel->getCode());
        $pricing->setPrice($priceCentimes);
        $variant->addChannelPricing($pricing);

        $em->persist($product);
        $em->flush();

        return $variant;
    }

    /**
     * Makes the channel's default tax zone carry a rate of $rate, included in prices or not, and
     * returns its tax category. The cart has no address, so Sylius taxes it with this zone.
     */
    public function createTaxCategoryWithRate(ChannelInterface $channel, float $rate, bool $includedInPrice): TaxCategoryInterface
    {
        $em = $this->container->get(EntityManagerInterface::class);
        $channel = $this->managed($channel);
        $suffix = strtoupper(bin2hex(random_bytes(4)));

        /** @var ZoneInterface $zone */
        $zone = $this->container->get('sylius.factory.zone')->createNew();
        $zone->setCode('PO_ZONE_' . $suffix);
        $zone->setName('PunchOut test zone');
        $zone->setType(ZoneInterface::TYPE_COUNTRY);
        $em->persist($zone);
        $channel->setDefaultTaxZone($zone);

        /** @var TaxCategoryInterface $category */
        $category = $this->container->get('sylius.factory.tax_category')->createNew();
        $category->setCode('PO_TAX_' . $suffix);
        $category->setName('PunchOut test tax');
        $em->persist($category);

        /** @var TaxRateInterface $taxRate */
        $taxRate = $this->container->get('sylius.factory.tax_rate')->createNew();
        $taxRate->setCode('PO_RATE_' . $suffix);
        $taxRate->setName('PunchOut test rate');
        $taxRate->setAmount($rate);
        $taxRate->setIncludedInPrice($includedInPrice);
        $taxRate->setCalculator('default');
        $taxRate->setCategory($category);
        $taxRate->setZone($zone);
        $em->persist($taxRate);
        $em->flush();

        return $category;
    }

    /**
     * Takes the channel out of every cart promotion (random fixtures), so prices are the catalog prices.
     * Rolled back with the test transaction.
     */
    public function withoutPromotions(ChannelInterface $channel): void
    {
        $channel = $this->managed($channel);
        foreach ($this->container->get('sylius.repository.promotion')->findAll() as $promotion) {
            if ($promotion instanceof \Sylius\Component\Core\Model\PromotionInterface) {
                $promotion->removeChannel($channel);
            }
        }
        $this->container->get(EntityManagerInterface::class)->flush();
    }

    /** Gives a buyer channel back as an entity managed by the current entity manager. */
    public function managed(ChannelInterface $channel): ChannelInterface
    {
        $found = $this->container->get('sylius.repository.channel')->find($channel->getId());
        if (!$found instanceof ChannelInterface) {
            throw new \RuntimeException('Channel not found');
        }

        return $found;
    }

    /** Creates a regular (non PunchOut) cart owned by the customer, as the shop does for a logged-in customer. */
    public function createRegularCart(BuyerInterface $buyer, ProductVariantInterface $variant): OrderInterface
    {
        $em = $this->container->get(EntityManagerInterface::class);
        $channel = $buyer->getChannel();
        $customer = $buyer->getCustomer();
        if (!$channel instanceof ChannelInterface || !$customer instanceof CustomerInterface) {
            throw new \RuntimeException('Buyer needs a channel and a customer');
        }
        $order = $this->buildCart($channel, $customer, $em);
        $order->setCustomerWithAuthorization($order->getCustomer());

        /** @var \Sylius\Component\Core\Model\OrderItemInterface $item */
        $item = $this->container->get('sylius.factory.order_item')->createNew();
        $item->setVariant($variant);
        $this->container->get('sylius.modifier.order_item_quantity')->modify($item, 2);
        $this->container->get('sylius.modifier.order')->addToOrder($order, $item);
        $this->container->get('sylius.order_processing.order_processor')->process($order);
        $em->flush();

        return $order;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** @phpstan-assert ProductVariantInterface $variant */
    private function assertVariant(mixed $variant): void
    {
        if (!$variant instanceof ProductVariantInterface) {
            throw new \RuntimeException('The product factory did not create a variant');
        }
    }

    private function buildCart(
        ?ChannelInterface $channel,
        ?CustomerInterface $customer,
        EntityManagerInterface $em,
    ): OrderInterface {
        // Re-fetch via repository to ensure entities are in the current EM identity map.
        // After HTTP requests through KernelBrowser, previously-loaded entities may be detached.
        $managedChannel = null;
        if ($channel instanceof \Sylius\Component\Core\Model\ChannelInterface) {
            $found = $this->container->get('sylius.repository.channel')->find($channel->getId());
            $managedChannel = $found instanceof ChannelInterface ? $found : null;
        }

        $managedCustomer = null;
        if ($customer instanceof \Sylius\Component\Core\Model\CustomerInterface) {
            $found = $this->container->get('sylius.repository.customer')->find($customer->getId());
            $managedCustomer = $found instanceof CustomerInterface ? $found : null;
        }

        /** @var OrderInterface $order */
        $order = $this->container->get('sylius.factory.order')->createNew();
        $order->setChannel($managedChannel);
        $order->setCustomer($managedCustomer);
        $order->setState('cart');
        $order->setCurrencyCode($channel?->getBaseCurrency()?->getCode() ?? 'EUR');
        $order->setLocaleCode($channel?->getDefaultLocale()?->getCode() ?? 'en_US');
        // Required Sylius checkout state machine fields
        if (method_exists($order, 'setCheckoutState')) {
            $order->setCheckoutState('cart');
        }
        $em->persist($order);

        return $order;
    }

    private function getDefaultChannel(): ?ChannelInterface
    {
        $repo = $this->container->get('sylius.repository.channel');
        $channel = $repo->findOneBy([]);

        return $channel instanceof ChannelInterface ? $channel : null;
    }

    private function getDefaultShopCustomer(): ?CustomerInterface
    {
        $repo = $this->container->get('sylius.repository.customer');
        $customer = $repo->findOneBy(['email' => 'shop@example.com']);

        return $customer instanceof CustomerInterface ? $customer : null;
    }
}
