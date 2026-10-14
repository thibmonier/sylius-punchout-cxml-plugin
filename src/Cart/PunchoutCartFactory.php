<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cart;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Storage\CartStorageInterface;
use Sylius\Resource\Factory\FactoryInterface;

/**
 * Creates a fresh, isolated cart for a PunchOut session.
 *
 * The cart is linked to the buyer's customer and stored in the channel
 * cart storage so that PunchoutCartContext can retrieve it during the session.
 */
final readonly class PunchoutCartFactory
{
    /**
     * @param FactoryInterface<OrderInterface> $orderFactory
     */
    public function __construct(
        private FactoryInterface $orderFactory,
        private CartStorageInterface $cartStorage,
    ) {
    }

    /**
     * Creates and stores a new cart for the given customer and channel.
     */
    public function create(CustomerInterface $customer, ChannelInterface $channel): OrderInterface
    {
        /** @var OrderInterface $cart */
        $cart = $this->orderFactory->createNew();

        $cart->setChannel($channel);
        $cart->setCurrencyCode($channel->getBaseCurrency()?->getCode() ?? 'EUR');
        $cart->setLocaleCode($channel->getDefaultLocale()?->getCode() ?? 'en_US');
        $cart->setCustomer($customer);

        // Store the cart in the channel storage so PunchoutCartContext can retrieve it
        $this->cartStorage->setForChannel($channel, $cart);

        return $cart;
    }
}
