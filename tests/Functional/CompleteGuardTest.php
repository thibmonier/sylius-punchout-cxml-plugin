<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;

/**
 * The `complete` transition of a PunchOut cart is refused (the cart goes back to the procurement system):
 * the checkout routes are blocked, and so is the API (PATCH /api/v2/shop/orders/{token}/complete).
 */
final class CompleteGuardTest extends WebTestCase
{
    use DatabaseTransactionTrait;
    use PunchoutRoundTripTrait;

    public function testAPunchoutCartCannotBeCompleted(): void
    {
        $cart = $this->punchoutCart();

        self::assertFalse($this->stateMachine()->can($cart, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_COMPLETE));
    }

    public function testACompletionByTheApiIsRefusedAndTheOrderStaysACart(): void
    {
        $cart = $this->punchoutCart();

        $this->patchComplete((string) $cart->getTokenValue());

        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->entityManager()->clear();
        $stored = $this->entityManager()->find(OrderInterface::class, $cart->getId());
        self::assertInstanceOf(OrderInterface::class, $stored);
        self::assertSame(OrderInterface::STATE_CART, $stored->getState());
    }

    public function testARegularCartCanBeCompleted(): void
    {
        $cart = $this->regularCart();

        self::assertTrue($this->stateMachine()->can($cart, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_COMPLETE));
    }

    public function testACompletionByTheApiOfARegularCartStillWorks(): void
    {
        $cart = $this->regularCart();

        $this->patchComplete((string) $cart->getTokenValue());

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->entityManager()->clear();
        $stored = $this->entityManager()->find(OrderInterface::class, $cart->getId());
        self::assertInstanceOf(OrderInterface::class, $stored);
        self::assertSame(OrderInterface::STATE_NEW, $stored->getState());
    }

    public function testAShopOrderCitingPunchoutInItsNotesIsCompletedNormally(): void
    {
        $cart = $this->regularCart();
        $cart->setNotes('PunchOut purchase order PO-1234');
        $this->entityManager()->flush();

        self::assertTrue($this->stateMachine()->can($cart, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_COMPLETE));
    }

    /** A cart opened by a PunchOut session, ready for the `complete` transition otherwise. */
    private function punchoutCart(): OrderInterface
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer(fromIdentity: 'guard-' . bin2hex(random_bytes(4)) . '@bigcorp.example');
        $channel = $buyer->getChannel();
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $catalog->withoutPromotions($channel);
        $variant = $catalog->createVariant($channel, 1250);
        $catalog->enableOrderRequests($buyer);
        $this->startPunchout($buyer, $secret);
        $cartId = $this->addToCurrentCart((string) $variant->getCode(), 2)['id'];

        $cart = $this->entityManager()->find(OrderInterface::class, $cartId);
        self::assertInstanceOf(OrderInterface::class, $cart);

        return $this->readyForCompletion($cart);
    }

    private function regularCart(): OrderInterface
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer] = $catalog->createBuyerWithCustomer(fromIdentity: 'guard-' . bin2hex(random_bytes(4)) . '@bigcorp.example');
        $channel = $buyer->getChannel();
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $catalog->withoutPromotions($channel);
        $variant = $catalog->createVariant($channel, 1250);
        $catalog->enableOrderRequests($buyer);

        $user = $buyer->getCustomer()?->getUser();
        self::assertInstanceOf(ShopUserInterface::class, $user);
        $this->client->loginUser($user, 'shop');

        return $this->readyForCompletion($catalog->createRegularCart($buyer, $variant));
    }

    private function readyForCompletion(OrderInterface $cart): OrderInterface
    {
        $cart->setTokenValue($cart->getTokenValue() ?? bin2hex(random_bytes(16)));
        $address = $this->address();
        $cart->setShippingAddress($address);
        $cart->setBillingAddress(clone $address);
        $this->stateMachine()->apply($cart, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_ADDRESS);

        $container = self::getContainer();
        $channel = $cart->getChannel();
        foreach ($cart->getShipments() as $shipment) {
            $shipment->setMethod($container->get('sylius.resolver.shipping_methods')->getSupportedMethods($shipment)[0] ?? null);
        }
        $this->stateMachine()->apply($cart, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING);

        $payment = $container->get('sylius.repository.payment_method')->findOneBy(['code' => $this->paymentCode($channel)]);
        foreach ($cart->getPayments() as $item) {
            $item->setMethod($payment);
        }
        $this->stateMachine()->apply($cart, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);
        $this->entityManager()->flush();

        return $cart;
    }

    private function paymentCode(?ChannelInterface $channel): ?string
    {
        foreach (self::getContainer()->get('sylius.repository.payment_method')->findBy(['enabled' => true]) as $method) {
            if ($channel !== null && $method->hasChannel($channel) && str_starts_with((string) $method->getCode(), 'PO_PAY_')) {
                return $method->getCode();
            }
        }

        return null;
    }

    private function address(): AddressInterface
    {
        $address = self::getContainer()->get('sylius.factory.address')->createNew();
        $address->setFirstName('Claire');
        $address->setLastName('Martin');
        $address->setStreet('12 rue de la Paix');
        $address->setCity('Paris');
        $address->setPostcode('75002');
        $address->setCountryCode('FR');

        return $address;
    }

    private function patchComplete(string $token): void
    {
        $this->client->request('PATCH', '/api/v2/shop/orders/' . $token . '/complete', [], [], [
            'CONTENT_TYPE' => 'application/merge-patch+json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ], '{}');
    }

    private function stateMachine(): StateMachineInterface
    {
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
        self::assertInstanceOf(StateMachineInterface::class, $stateMachine);

        return $stateMachine;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
