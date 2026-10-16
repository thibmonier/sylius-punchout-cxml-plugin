<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cart;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Factory\FactoryInterface;

/**
 * Creates a fresh, isolated cart for a PunchOut session (R1).
 *
 * The cart is NOT stored in the Sylius channel cart storage (which would
 * overwrite the shopper's regular cart). Instead, it is exclusively accessed
 * via PunchoutSession::getCart(), through PunchoutCartContext.
 */
final readonly class PunchoutCartFactory
{
    /**
     * @param FactoryInterface<OrderInterface> $orderFactory
     */
    public function __construct(
        private FactoryInterface $orderFactory,
    ) {
    }

    /**
     * Creates a new isolated cart for the given customer and channel.
     *
     * The cart is left in state='cart' / checkoutState='cart' and must be
     * persisted and linked to the PunchoutSession by the caller.
     */
    public function create(CustomerInterface $customer, ChannelInterface $channel): OrderInterface
    {
        /** @var OrderInterface $cart */
        $cart = $this->orderFactory->createNew();

        $cart->setChannel($channel);
        $cart->setCurrencyCode($channel->getBaseCurrency()?->getCode() ?? 'EUR');
        $cart->setLocaleCode($channel->getDefaultLocale()?->getCode() ?? 'en_US');
        // Not setCustomer(): it leaves the cart "created by guest", and the trade-credit plugin refuses
        // on-account payment for a guest order. The customer is the one the buyer is linked to.
        $cart->setCustomerWithAuthorization($customer);
        $cart->setState('cart');

        $cart->setCheckoutState('cart');

        return $cart;
    }
}
