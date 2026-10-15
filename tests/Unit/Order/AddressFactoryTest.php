<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Unit\Order;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\CxmlAddress;
use Tmonier\SyliusPunchoutCxmlPlugin\Order\AddressFactory;
use Tmonier\SyliusPunchoutCxmlPlugin\Order\OrderRequestRejected;

final class AddressFactoryTest extends TestCase
{
    public function testDeliverToGivesTheNamesAndNameTheCompany(): void
    {
        $address = $this->factory()->create(new CxmlAddress('BigCorp', 'Claire Anne Martin', ['12 rue X', 'Bât. B'], 'Paris', '75002', 'FR'), 'ShipTo');

        self::assertSame(['Claire', 'Anne Martin', 'BigCorp', '12 rue X, Bât. B'], [$address->getFirstName(), $address->getLastName(), $address->getCompany(), $address->getStreet()]);
    }

    public function testWithoutDeliverToTheCompanyNameIsUsed(): void
    {
        $address = $this->factory()->create(new CxmlAddress('Acme', '', ['1 rue Y'], 'Lyon', '69001', 'FR'), 'BillTo');

        self::assertSame(['Acme', 'Acme'], [$address->getFirstName(), $address->getLastName()]);
    }

    public function testAnIncompleteAddressIsRefused(): void
    {
        $this->expectException(OrderRequestRejected::class);
        $this->expectExceptionMessage('ShipTo address incomplete');

        $this->factory()->create(new CxmlAddress('Acme', 'Paul', ['1 rue Y'], 'Lyon', '', 'FR'), 'ShipTo');
    }

    private function factory(): AddressFactory
    {
        return new AddressFactory(new
            /** @implements FactoryInterface<AddressInterface> */
            class() implements FactoryInterface {
                public function createNew(): AddressInterface
                {
                    return new Address();
                }
            });
    }
}
