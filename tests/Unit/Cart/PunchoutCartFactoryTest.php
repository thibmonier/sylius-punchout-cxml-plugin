<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Unit\Cart;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Cart\PunchoutCartFactory;

final class PunchoutCartFactoryTest extends TestCase
{
    public function testTheCartOfAnAuthorizedCustomerIsNotAGuestCart(): void
    {
        $customer = new Customer();
        $customer->setEmail('buyer@example.com');

        $cart = $this->factory()->create($customer, $this->createStub(ChannelInterface::class));

        self::assertSame($customer, $cart->getCustomer());
        self::assertFalse($cart->isCreatedByGuest(), 'A guest order is refused on account by the trade-credit plugin');
    }

    private function factory(): PunchoutCartFactory
    {
        $orders = $this->createStub(FactoryInterface::class);
        $orders->method('createNew')->willReturnCallback(static fn (): OrderInterface => new Order());

        return new PunchoutCartFactory($orders);
    }
}
