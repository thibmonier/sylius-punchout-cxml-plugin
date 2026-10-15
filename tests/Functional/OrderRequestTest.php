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

    /** @param list<array{string, int, string}> $lines part number, quantity, unit price */
    private function orderRequestXml(Buyer $buyer, string $secret, string $orderId, array $lines, string $type = 'new'): string
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
        $payloadId = bin2hex(random_bytes(8)) . '@test.local';
        $currency = (string) $buyer->getChannel()?->getBaseCurrency()?->getCode();
        $total = number_format(array_sum(array_map(static fn (array $line): float => $line[1] * (float) $line[2], $lines)), 2, '.', '');
        $items = '';
        foreach ($lines as $index => [$part, $quantity, $price]) {
            $items .= sprintf(
                '<ItemOut quantity="%d" lineNumber="%d"><ItemID><SupplierPartID>%s</SupplierPartID><SupplierPartAuxiliaryID>v1:%s</SupplierPartAuxiliaryID></ItemID><ItemDetail><UnitPrice><Money currency="%s">%s</Money></UnitPrice><Description xml:lang="en">Item</Description><UnitOfMeasure>EA</UnitOfMeasure></ItemDetail></ItemOut>',
                $quantity,
                $index + 1,
                $part,
                $part,
                $currency,
                $price,
            );
        }
        $address = static fn (string $id, string $name, string $deliverTo, string $street, string $city, string $postcode): string => <<<XML
            <Address isoCountryCode="FR" addressID="{$id}"><Name xml:lang="fr">{$name}</Name><PostalAddress><DeliverTo>{$deliverTo}</DeliverTo><Street>{$street}</Street><City>{$city}</City><PostalCode>{$postcode}</PostalCode><Country isoCountryCode="FR">France</Country></PostalAddress></Address>
            XML;
        $shipTo = $address('S1', 'BigCorp Logistique', 'Claire Martin', '12 rue de la Paix', 'Paris', '75002');
        $billTo = $address('B1', 'BigCorp SA', 'Paul Durand', '1 avenue des Comptes', 'Lyon', '69001');

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE cXML SYSTEM "http://xml.cxml.org/schemas/cXML/1.2.071/cXML.dtd">
            <cXML payloadID="{$payloadId}" timestamp="{$timestamp}">
                <Header>
                    <From><Credential domain="{$from->getDomain()}"><Identity>{$from->getIdentity()}</Identity></Credential></From>
                    <To><Credential domain="NetworkId"><Identity>supplier</Identity></Credential></To>
                    <Sender><Credential domain="{$sender->getDomain()}"><Identity>{$sender->getIdentity()}</Identity><SharedSecret>{$secret}</SharedSecret></Credential><UserAgent>Test</UserAgent></Sender>
                </Header>
                <Request deploymentMode="test">
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

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
