<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Model\AdjustmentInterface;
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
     * @return array{buyer: Buyer, secret: string}
     */
    public function createBuyer(
        string $fromDomain = 'AribaNetworkUserId',
        string $fromIdentity = 'test-buyer@example.com',
        string $senderDomain = 'AribaNetworkUserId',
        string $senderIdentity = 'test-system@example.com',
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
     * @return array{buyer: Buyer, secret: string}
     */
    public function createBuyerWithCustomer(
        string $fromDomain = 'AribaNetworkUserId',
        string $fromIdentity = 'test-buyer-e2e@example.com',
        string $senderDomain = 'AribaNetworkUserId',
        string $senderIdentity = 'test-system-e2e@example.com',
    ): array {
        ['buyer' => $buyer, 'secret' => $secret] = $this->createBuyer(
            $fromDomain,
            $fromIdentity,
            $senderDomain,
            $senderIdentity,
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
    public function createBuyerWithTaxIncluded(): array
    {
        return $this->createBuyerWithCustomer(
            fromIdentity: 'test-buyer-ttc@example.com',
            senderIdentity: 'test-system-ttc@example.com',
        );
    }

    /**
     * Adds an order item (and one unit per quantity) directly to an existing order.
     * Used to populate the PunchOut session's isolated cart (R1+R2).
     *
     * @param int $unitPriceCentimes unit price in centimes (e.g. 10000 = 100.00 EUR)
     */
    public function addItemToOrder(
        OrderInterface $order,
        int $unitPriceCentimes = 10000,
        int $quantity = 1,
    ): void {
        $em = $this->container->get(EntityManagerInterface::class);
        $variant = $this->getDefaultProductVariant();

        $item = $this->buildOrderItem($variant, $unitPriceCentimes, $order, $em);
        for ($i = 0; $i < $quantity; ++$i) {
            $this->buildOrderItemUnit($item, $em);
        }
        $em->flush();
    }

    /**
     * Creates a Sylius cart (Order in state=cart) for the buyer's customer/channel,
     * with one item pointing to an existing fixture product variant.
     *
     * @param int $unitPriceCentimes unit price in centimes (e.g. 10000 = 100.00 EUR)
     */
    public function createCartWithItem(
        BuyerInterface $buyer,
        int $unitPriceCentimes = 10000,
        int $quantity = 1,
    ): OrderInterface {
        $em = $this->container->get(EntityManagerInterface::class);
        $channel = $buyer->getChannel();
        $customer = $buyer->getCustomer();

        // Use an existing product variant from fixtures
        $variant = $this->getDefaultProductVariant();

        $order = $this->buildCart($channel, $customer, $em);

        $item = $this->buildOrderItem($variant, $unitPriceCentimes, $order, $em);

        // Add one OrderItemUnit per quantity (required by Sylius order item quantity tracking)
        for ($i = 0; $i < $quantity; ++$i) {
            $this->buildOrderItemUnit($item, $em);
        }

        $em->flush();

        return $order;
    }

    /**
     * Creates a cart with a tax-inclusive item (120.00 TTC, 20% tax → 100.00 HT).
     * unitPrice = 12000 centimes, taxAdjustment per unit = 2000 centimes (neutral).
     *
     * If $existingOrder is provided, items are added to it instead of creating a new cart.
     * This supports R1+R2 where TransferAction uses the PunchOut session's isolated cart.
     */
    public function createCartWithTaxItem(
        BuyerInterface $buyer,
        ?OrderInterface $existingOrder = null,
    ): OrderInterface {
        $em = $this->container->get(EntityManagerInterface::class);
        $channel = $buyer->getChannel();
        $customer = $buyer->getCustomer();

        $variant = $this->getDefaultProductVariant();

        if ($existingOrder instanceof \Sylius\Component\Core\Model\OrderInterface) {
            // Re-attach to current EM (may be detached after HTTP requests)
            $order = $em->find($existingOrder::class, $existingOrder->getId());
            if (!$order instanceof OrderInterface) {
                $order = $existingOrder;
            }
        } else {
            $order = $this->buildCart($channel, $customer, $em);
        }

        // 120.00 EUR TTC (= 12000 centimes)
        $item = $this->buildOrderItem($variant, 12000, $order, $em);

        // One unit with a neutral tax adjustment of 2000 centimes (20.00 EUR embedded tax)
        $unit = $this->buildOrderItemUnit($item, $em);
        $adjustment = $this->buildTaxAdjustment(2000, $em);
        $unit->addAdjustment($adjustment);
        $em->persist($unit);

        $em->flush();

        return $order;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

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

    private function buildOrderItem(
        ProductVariantInterface $variant,
        int $unitPriceCentimes,
        OrderInterface $order,
        EntityManagerInterface $em,
    ): \Sylius\Component\Core\Model\OrderItemInterface {
        /** @var \Sylius\Component\Core\Model\OrderItemInterface $item */
        $item = $this->container->get('sylius.factory.order_item')->createNew();
        $item->setVariant($variant);
        $item->setProductName($variant->getName() ?? $variant->getCode() ?? 'Test Product');
        $item->setUnitPrice($unitPriceCentimes);
        // fullDiscountedUnitPrice = unitPrice for fresh items (no promotions)
        if (method_exists($item, 'setFullDiscountedUnitPrice')) {
            $item->setFullDiscountedUnitPrice($unitPriceCentimes);
        }
        $order->addItem($item);
        $em->persist($item);

        return $item;
    }

    private function buildOrderItemUnit(
        \Sylius\Component\Core\Model\OrderItemInterface $item,
        EntityManagerInterface $em,
    ): \Sylius\Component\Core\Model\OrderItemUnitInterface {
        /** @var \Sylius\Component\Core\Model\OrderItemUnitInterface $unit */
        $unit = $this->container->get('sylius.factory.order_item_unit')->createForItem($item);
        $em->persist($unit);

        return $unit;
    }

    private function buildTaxAdjustment(int $amountCentimes, EntityManagerInterface $em): AdjustmentInterface
    {
        /** @var AdjustmentInterface $adjustment */
        $adjustment = $this->container->get('sylius.factory.adjustment')->createNew();
        $adjustment->setType(\Sylius\Component\Core\Model\AdjustmentInterface::TAX_ADJUSTMENT);
        $adjustment->setAmount($amountCentimes);
        // R5: neutral=true means the tax is embedded in the unit price (TTC/tax-inclusive).
        // NetPriceCalculator strips neutral tax to get the net price.
        $adjustment->setNeutral(true);
        $adjustment->setLabel('VAT 20%');
        $em->persist($adjustment);

        return $adjustment;
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

    private function getDefaultProductVariant(): ProductVariantInterface
    {
        $repo = $this->container->get('sylius.repository.product_variant');
        $variants = $repo->findBy([], ['id' => 'ASC'], 1);
        if (empty($variants)) {
            throw new \RuntimeException('No product variants found in fixtures. Run sylius:fixtures:load first.');
        }
        $variant = $variants[0];
        if (!$variant instanceof ProductVariantInterface) {
            throw new \RuntimeException('First product variant is not a ProductVariantInterface');
        }

        return $variant;
    }
}
