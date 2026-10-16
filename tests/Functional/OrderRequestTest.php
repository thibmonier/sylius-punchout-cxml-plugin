<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerIdentity;
use Tmonier\SyliusPunchoutCxmlPlugin\Order\PunchoutOrderCreated;

/** US-030: the approved purchase order becomes a Sylius order, once. */
final class OrderRequestTest extends WebTestCase
{
    use DatabaseTransactionTrait;
    use PunchoutRoundTripTrait;

    public function testAnApprovedPurchaseOrderBecomesASyliusOrderOnce(): void
    {
        ['buyer' => $buyer, 'secret' => $secret, 'variant' => $variant, 'methods' => $methods] = $this->orderingBuyer();
        $orderId = 'PO-' . bin2hex(random_bytes(4));

        $body = $this->postSetup($this->orderRequestXml($buyer, $secret, $orderId, [[(string) $variant->getCode(), 2, '12.50']]));

        self::assertStringContainsString('<Status code="200"', $body, $body);
        $this->assertValidAgainstTheDtd($body);
        $order = $this->order($orderId);
        self::assertSame(OrderInterface::STATE_NEW, $order->getState());
        self::assertSame($buyer->getCustomer()?->getId(), $order->getCustomer()?->getId());
        self::assertSame([(string) $variant->getCode(), 2], [$order->getItems()->first() ? $order->getItems()->first()->getVariant()?->getCode() : null, $order->getTotalQuantity()]);
        self::assertSame(['Claire', 'Martin', 'BigCorp Logistique', '12 rue de la Paix', 'Paris', '75002', 'FR'], $this->address($order->getShippingAddress()));
        self::assertSame(['Paul', 'Durand', 'BigCorp SA', '1 avenue des Comptes', 'Lyon', '69001', 'FR'], $this->address($order->getBillingAddress()));
        self::assertNotNull($order->getNumber());
        self::assertStringContainsString('Order ' . $order->getNumber(), $body);
        self::assertSame([OrderCheckoutStates::STATE_COMPLETED, OrderPaymentStates::STATE_AWAITING_PAYMENT], [$order->getCheckoutState(), $order->getPaymentState()]);
        self::assertSame($methods['payment'], $order->getLastPayment()?->getMethod()?->getCode());
        self::assertSame($methods['shipping'], $order->getShipments()->first() ? $order->getShipments()->first()->getMethod()?->getCode() : null);
        self::assertSame(2500, $order->getTotal(), 'Catalog price, free shipping, no tax');
        self::assertStringStartsWith('PunchOut purchase order ' . $orderId, (string) $order->getNotes());

        $again = $this->postSetup($this->orderRequestXml($buyer, $secret, $orderId, [[(string) $variant->getCode(), 2, '12.50']]));
        self::assertStringContainsString('<Status code="200"', $again, $again);
        self::assertSame(1, $this->countOrders($orderId), 'A reposted purchase order creates no new order');
    }

    public function testTheOrderBelongsToAnAuthorizedCustomerNotToAGuest(): void
    {
        ['buyer' => $buyer, 'secret' => $secret, 'variant' => $variant] = $this->orderingBuyer();
        $orderId = 'PO-' . bin2hex(random_bytes(4));

        $this->postSetup($this->orderRequestXml($buyer, $secret, $orderId, [[(string) $variant->getCode(), 1, '12.50']]));

        self::assertFalse($this->order($orderId)->isCreatedByGuest(), 'The trade-credit plugin refuses on-account payment for a guest order');
    }

    public function testTheCreationOfTheOrderIsAnnouncedOnceWithThePurchaseOrderId(): void
    {
        ['buyer' => $buyer, 'secret' => $secret, 'variant' => $variant] = $this->orderingBuyer();
        $orderId = 'PO-' . bin2hex(random_bytes(4));
        $events = $this->recordedEvents();

        $this->postSetup($this->orderRequestXml($buyer, $secret, $orderId, [[(string) $variant->getCode(), 1, '12.50']]));
        $this->postSetup($this->orderRequestXml($buyer, $secret, $orderId, [[(string) $variant->getCode(), 1, '12.50']]));

        self::assertCount(1, $events, 'A repost creates no order, so it announces nothing');
        $order = $this->order($orderId);
        self::assertSame($orderId, $events[0]->purchaseOrderId);
        self::assertSame($order->getId(), $events[0]->order->getId());
        self::assertSame($buyer->getId(), $events[0]->buyer->getId());
    }

    public function testARefusedPurchaseOrderAnnouncesNothing(): void
    {
        ['buyer' => $buyer, 'secret' => $secret, 'variant' => $variant] = $this->orderingBuyer();
        $events = $this->recordedEvents();

        $body = $this->postSetup($this->orderRequestXml($buyer, $secret, 'PO-' . bin2hex(random_bytes(4)), [[(string) $variant->getCode(), 1, '99.99']]));

        self::assertStringContainsString('Prices changed', $body);
        self::assertSame([], $events->getArrayCopy());
    }

    public function testTheVerySameDocumentSentAgainGetsTheSameOrder(): void
    {
        ['buyer' => $buyer, 'secret' => $secret, 'variant' => $variant] = $this->orderingBuyer();
        $orderId = 'PO-' . bin2hex(random_bytes(4));
        $xml = $this->orderRequestXml($buyer, $secret, $orderId, [[(string) $variant->getCode(), 1, '12.50']]);

        $first = $this->postSetup($xml);
        $second = $this->postSetup($xml);

        self::assertStringContainsString('<Status code="200"', $second, 'A transport retry (same payloadID) is not a replay');
        self::assertSame($this->orderNumberIn($first), $this->orderNumberIn($second));
        self::assertSame(1, $this->countOrders($orderId));
    }

    public function testAnOrderIdReusedWithAnotherContentIsRefused(): void
    {
        ['buyer' => $buyer, 'secret' => $secret, 'variant' => $variant] = $this->orderingBuyer();
        $orderId = 'PO-' . bin2hex(random_bytes(4));
        $this->postSetup($this->orderRequestXml($buyer, $secret, $orderId, [[(string) $variant->getCode(), 1, '12.50']]));

        $body = $this->postSetup($this->orderRequestXml($buyer, $secret, $orderId, [[(string) $variant->getCode(), 3, '12.50']]));

        self::assertStringContainsString('<Status code="400"', $body);
        self::assertStringContainsString('different content', $body);
    }

    public function testQuantitiesOutsideTheAcceptedRangeAreRefused(): void
    {
        ['buyer' => $buyer, 'secret' => $secret, 'variant' => $variant] = $this->orderingBuyer();
        $code = (string) $variant->getCode();

        foreach (['20000', '2.5', '0'] as $quantity) {
            self::assertStringContainsString('<Status code="400"', $this->postSetup($this->orderRequestXml($buyer, $secret, 'PO-Q' . bin2hex(random_bytes(3)), [[$code, $quantity, '12.50']])), $quantity);
        }
        $body = $this->postSetup($this->orderRequestXml($buyer, $secret, 'PO-QQ' . bin2hex(random_bytes(3)), [[$code, 6000, '12.50'], [$code, 6000, '12.50']]));
        self::assertStringContainsString('<Status code="400"', $body, 'Repeated lines are not capped silently');
    }

    public function testAnItemOutOfStockRefusesThePurchaseOrder(): void
    {
        ['buyer' => $buyer, 'secret' => $secret, 'variant' => $variant] = $this->orderingBuyer();
        $variant->setTracked(true);
        $variant->setOnHand(5);
        $this->entityManager()->flush();
        $orderId = 'PO-' . bin2hex(random_bytes(4));

        $body = $this->postSetup($this->orderRequestXml($buyer, $secret, $orderId, [[(string) $variant->getCode(), 10, '12.50']]));

        self::assertStringContainsString('<Status code="400"', $body);
        self::assertStringContainsString('Insufficient stock', $body);
        self::assertSame(0, $this->countOrders($orderId));
    }

    public function testACountryTheShopDoesNotServeIsRefused(): void
    {
        ['buyer' => $buyer, 'secret' => $secret, 'variant' => $variant] = $this->orderingBuyer();

        $body = $this->postSetup($this->orderRequestXml($buyer, $secret, 'PO-' . bin2hex(random_bytes(4)), [[(string) $variant->getCode(), 1, '12.50']], billToCountry: 'ZZ'));

        self::assertStringContainsString('<Status code="400"', $body);
        self::assertStringContainsString('BillTo country ZZ', $body);
    }

    public function testATestPurchaseOrderCreatesNoOrder(): void
    {
        ['buyer' => $buyer, 'secret' => $secret, 'variant' => $variant] = $this->orderingBuyer();
        $orderId = 'PO-' . bin2hex(random_bytes(4));

        self::assertStringContainsString('<Status code="450"', $this->postSetup($this->orderRequestXml($buyer, $secret, $orderId, [[(string) $variant->getCode(), 1, '12.50']], mode: 'test')));
        self::assertSame(0, $this->countOrders($orderId));
    }

    public function testAPurchaseOrderAtAnOutdatedPriceIsRefused(): void
    {
        ['buyer' => $buyer, 'secret' => $secret, 'variant' => $variant] = $this->orderingBuyer();
        $orderId = 'PO-' . bin2hex(random_bytes(4));

        $body = $this->postSetup($this->orderRequestXml($buyer, $secret, $orderId, [[(string) $variant->getCode(), 1, '10.00']]));

        self::assertStringContainsString('<Status code="400"', $body);
        self::assertStringContainsString((string) $variant->getCode(), $body, 'The line is named');
        self::assertSame(0, $this->countOrders($orderId));
    }

    public function testAnItemNoLongerSoldRefusesTheWholePurchaseOrder(): void
    {
        ['buyer' => $buyer, 'secret' => $secret, 'variant' => $variant] = $this->orderingBuyer();
        $orderId = 'PO-' . bin2hex(random_bytes(4));

        $body = $this->postSetup($this->orderRequestXml($buyer, $secret, $orderId, [[(string) $variant->getCode(), 1, '12.50'], ['UNKNOWN-PART', 1, '5.00']]));

        self::assertStringContainsString('<Status code="400"', $body);
        self::assertStringContainsString('UNKNOWN-PART', $body);
        self::assertSame(0, $this->countOrders($orderId));
    }

    public function testABuyerWithoutPaymentMethodReceivesNoOrder(): void
    {
        ['buyer' => $buyer, 'secret' => $secret, 'variant' => $variant] = $this->orderingBuyer();
        $buyer->setOrderPaymentMethod(null);
        $this->entityManager()->flush();
        $orderId = 'PO-' . bin2hex(random_bytes(4));

        self::assertStringContainsString('<Status code="450"', $this->postSetup($this->orderRequestXml($buyer, $secret, $orderId, [[(string) $variant->getCode(), 1, '12.50']])));
        self::assertSame(0, $this->countOrders($orderId));
    }

    public function testAnUpdateOfAPurchaseOrderIsNotImplemented(): void
    {
        ['buyer' => $buyer, 'secret' => $secret, 'variant' => $variant] = $this->orderingBuyer();

        $body = $this->postSetup($this->orderRequestXml($buyer, $secret, 'PO-UPD', [[(string) $variant->getCode(), 1, '12.50']], 'update'));

        self::assertStringContainsString('<Status code="450"', $body);
    }

    public function testAWrongSecretIsRefused(): void
    {
        ['buyer' => $buyer, 'variant' => $variant] = $this->orderingBuyer();
        $orderId = 'PO-' . bin2hex(random_bytes(4));

        self::assertStringContainsString('<Status code="401"', $this->postSetup($this->orderRequestXml($buyer, 'wrong-' . bin2hex(random_bytes(8)), $orderId, [[(string) $variant->getCode(), 1, '12.50']])));
        self::assertSame(0, $this->countOrders($orderId));
    }

    /** @return array{buyer: Buyer, secret: string, variant: ProductVariantInterface, methods: array{shipping: string, payment: string}} */
    private function orderingBuyer(): array
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer(fromIdentity: 'po-' . bin2hex(random_bytes(4)) . '@bigcorp.example');
        $channel = $buyer->getChannel();
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $catalog->withoutPromotions($channel);
        $variant = $catalog->createVariant($channel, 1250);
        $methods = $catalog->enableOrderRequests($buyer);

        return ['buyer' => $buyer, 'secret' => $secret, 'variant' => $variant, 'methods' => $methods];
    }

    /** @param list<array{string, int|string, string}> $lines part number, quantity, unit price */
    private function orderRequestXml(Buyer $buyer, string $secret, string $orderId, array $lines, string $type = 'new', string $mode = 'production', string $billToCountry = 'FR', ?string $payloadId = null): string
    {
        $identity = static function (string $role) use ($buyer): BuyerIdentity {
            foreach ($buyer->getIdentities() as $identity) {
                if ($identity->getRole() === $role) {
                    return $identity;
                }
            }

            throw new \LogicException($role);
        };
        $from = $identity(BuyerIdentity::ROLE_FROM);
        $sender = $identity(BuyerIdentity::ROLE_SENDER);
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339);
        $payloadId ??= bin2hex(random_bytes(8)) . '@test.local';
        $currency = (string) $buyer->getChannel()?->getBaseCurrency()?->getCode();
        $total = number_format(array_sum(array_map(static fn (array $line): float => (float) $line[1] * (float) $line[2], $lines)), 2, '.', '');
        $items = '';
        foreach ($lines as $index => [$part, $quantity, $price]) {
            $items .= sprintf(
                '<ItemOut quantity="%s" lineNumber="%d"><ItemID><SupplierPartID>%s</SupplierPartID><SupplierPartAuxiliaryID>v1:%s</SupplierPartAuxiliaryID></ItemID><ItemDetail><UnitPrice><Money currency="%s">%s</Money></UnitPrice><Description xml:lang="en">Item</Description><UnitOfMeasure>EA</UnitOfMeasure></ItemDetail></ItemOut>',
                $quantity,
                $index + 1,
                $part,
                $part,
                $currency,
                $price,
            );
        }
        $address = static fn (string $id, string $name, string $deliverTo, string $street, string $city, string $postcode, string $country = 'FR'): string => <<<XML
            <Address isoCountryCode="{$country}" addressID="{$id}"><Name xml:lang="fr">{$name}</Name><PostalAddress><DeliverTo>{$deliverTo}</DeliverTo><Street>{$street}</Street><City>{$city}</City><PostalCode>{$postcode}</PostalCode><Country isoCountryCode="{$country}">France</Country></PostalAddress></Address>
            XML;
        $shipTo = $address('S1', 'BigCorp Logistique', 'Claire Martin', '12 rue de la Paix', 'Paris', '75002');
        $billTo = $address('B1', 'BigCorp SA', 'Paul Durand', '1 avenue des Comptes', 'Lyon', '69001', $billToCountry);

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE cXML SYSTEM "http://xml.cxml.org/schemas/cXML/1.2.071/cXML.dtd">
            <cXML payloadID="{$payloadId}" timestamp="{$timestamp}">
                <Header>
                    <From><Credential domain="{$from->getDomain()}"><Identity>{$from->getIdentity()}</Identity></Credential></From>
                    <To><Credential domain="NetworkId"><Identity>supplier</Identity></Credential></To>
                    <Sender><Credential domain="{$sender->getDomain()}"><Identity>{$sender->getIdentity()}</Identity><SharedSecret>{$secret}</SharedSecret></Credential><UserAgent>Test</UserAgent></Sender>
                </Header>
                <Request deploymentMode="{$mode}">
                    <OrderRequest>
                        <OrderRequestHeader orderID="{$orderId}" orderDate="{$timestamp}" type="{$type}">
                            <Total><Money currency="{$currency}">{$total}</Money></Total>
                            <ShipTo>{$shipTo}</ShipTo>
                            <BillTo>{$billTo}</BillTo>
                        </OrderRequestHeader>
                        {$items}
                    </OrderRequest>
                </Request>
            </cXML>
            XML;
    }

    private function orderNumberIn(string $body): string
    {
        if (1 !== preg_match('/Order (\S+)</', $body, $matches)) {
            self::fail($body);
        }

        return $matches[1];
    }

    private function order(string $orderId): OrderInterface
    {
        $this->entityManager()->clear();
        $order = $this->entityManager()->getRepository(OrderInterface::class)->createQueryBuilder('o')
            ->andWhere('o.notes LIKE :notes')->setParameter('notes', '%' . $orderId . '%')
            ->getQuery()->getOneOrNullResult();
        self::assertInstanceOf(OrderInterface::class, $order);

        return $order;
    }

    private function countOrders(string $orderId): int
    {
        return (int) $this->entityManager()->getRepository(OrderInterface::class)->createQueryBuilder('o')
            ->select('COUNT(o.id)')->andWhere('o.notes LIKE :notes')->setParameter('notes', '%' . $orderId . '%')
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<string|null> */
    private function address(?\Sylius\Component\Core\Model\AddressInterface $address): array
    {
        self::assertNotNull($address);

        return [$address->getFirstName(), $address->getLastName(), $address->getCompany(), $address->getStreet(), $address->getCity(), $address->getPostcode(), $address->getCountryCode()];
    }

    /** @return \ArrayObject<int, PunchoutOrderCreated> */
    private function recordedEvents(): \ArrayObject
    {
        /** @var \ArrayObject<int, PunchoutOrderCreated> $events */
        $events = new \ArrayObject();
        self::getContainer()->get('event_dispatcher')->addListener(PunchoutOrderCreated::class, static function (PunchoutOrderCreated $event) use ($events): void {
            $events[] = $event;
        });

        return $events;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
