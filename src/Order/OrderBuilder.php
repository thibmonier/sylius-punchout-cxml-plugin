<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Order;

use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Shipping\Model\ShippingMethodInterface;
use Sylius\Component\Shipping\Resolver\ShippingMethodsResolverInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Cart\CartRebuilder;
use Tmonier\SyliusPunchoutCxmlPlugin\Cart\PunchoutCartFactory;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\OrderRequestData;
use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\NetPriceCalculator;

/**
 * Turns an OrderRequest into a completed Sylius order (ADR-0008): items sold on the channel, Sylius
 * prices equal to the purchase order prices, header addresses, the buyer's payment method and shipping
 * method (else the first eligible one), then the checkout transitions up to `complete`.
 */
final readonly class OrderBuilder
{
    private const CHECKOUT = [
        OrderCheckoutTransitions::TRANSITION_ADDRESS,
        OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING,
        OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT,
        OrderCheckoutTransitions::TRANSITION_COMPLETE,
    ];

    /**
     * @param RepositoryInterface<PaymentMethodInterface> $paymentMethodRepository
     */
    public function __construct(
        private PunchoutCartFactory $cartFactory,
        private CartRebuilder $cartRebuilder,
        private AddressFactory $addressFactory,
        private NetPriceCalculator $netPriceCalculator,
        private OrderProcessorInterface $orderProcessor,
        private ShippingMethodsResolverInterface $shippingMethodsResolver,
        private RepositoryInterface $paymentMethodRepository,
        private StateMachineInterface $stateMachine,
    ) {
    }

    public function build(BuyerInterface $buyer, OrderRequestData $request): OrderInterface
    {
        $order = $this->itemsOrder($buyer, $request);
        $order->setShippingAddress($this->addressFactory->create($request->shipTo, 'ShipTo'));
        $order->setBillingAddress($this->addressFactory->create($request->billTo, 'BillTo'));
        $order->setNotes(trim(sprintf("PunchOut purchase order %s\n%s", $request->orderId, $request->comments)));
        $this->orderProcessor->process($order);
        $this->checkPrices($order, $request);
        $this->chooseShipping($order, $buyer);
        $this->choosePayment($order, $buyer);
        $this->orderProcessor->process($order);
        $this->complete($order);

        return $order;
    }

    private function itemsOrder(BuyerInterface $buyer, OrderRequestData $request): OrderInterface
    {
        $customer = $buyer->getCustomer();
        $channel = $buyer->getChannel();
        if (!$customer instanceof CustomerInterface || !$channel instanceof ChannelInterface) {
            throw new OrderRequestRejected('The buyer has no customer or channel', \Tmonier\SyliusPunchoutCxmlPlugin\Cxml\CxmlStatus::OPERATION_FAILED);
        }
        $order = $this->cartFactory->create($customer, $channel);
        $leftOut = $this->cartRebuilder->rebuild($order, array_map(static fn (\Tmonier\SyliusPunchoutCxmlPlugin\Cxml\OrderRequestItem $item): array => ['supplierPartAuxId' => 'v1:' . $item->variantCode, 'quantity' => $item->quantity], $request->items));
        if ($leftOut !== []) {
            throw new OrderRequestRejected('Items not available: ' . implode(', ', array_map(static fn (string $code): string => mb_substr($code, 0, 40), array_slice($leftOut, 0, 20))));
        }

        return $order;
    }

    /** The purchase order is refused when a Sylius net unit price (or the currency) differs from it. */
    private function checkPrices(OrderInterface $order, OrderRequestData $request): void
    {
        $ordered = [];
        foreach ($request->items as $item) {
            if ($item->currency !== $order->getCurrencyCode()) {
                throw new OrderRequestRejected(sprintf('Currency %s differs from the shop currency %s', mb_substr($item->currency, 0, 3), $order->getCurrencyCode()));
            }
            $ordered[$item->variantCode][] = (float) $item->unitPrice;
        }
        $changed = [];
        foreach ($order->getItems() as $orderItem) {
            /** @var OrderItemInterface $orderItem */
            $code = (string) $orderItem->getVariant()?->getCode();
            $current = (float) $this->netPriceCalculator->calculateUnitNetPrice($orderItem);
            foreach ($ordered[$code] ?? [] as $price) {
                if (abs($price - $current) >= 0.005) {
                    $changed[$code] = sprintf('%s (%.2f, now %.2f)', $code, $price, $current);
                }
            }
        }
        if ($changed !== []) {
            throw new OrderRequestRejected('Prices changed: ' . implode(', ', array_slice($changed, 0, 20)));
        }
    }

    private function chooseShipping(OrderInterface $order, BuyerInterface $buyer): void
    {
        foreach ($order->getShipments() as $shipment) {
            /** @var ShipmentInterface $shipment */
            $supported = $this->shippingMethodsResolver->getSupportedMethods($shipment);
            $wanted = $buyer->getOrderShippingMethod();
            $method = $wanted === null
                ? ($supported[0] ?? null)
                : array_values(array_filter($supported, static fn (ShippingMethodInterface $method): bool => $method->getCode() === $wanted))[0] ?? null;
            if (!$method instanceof ShippingMethodInterface) {
                throw new OrderRequestRejected('No shipping method available for the ShipTo address');
            }
            $shipment->setMethod($method);
        }
    }

    private function choosePayment(OrderInterface $order, BuyerInterface $buyer): void
    {
        $method = $this->paymentMethodRepository->findOneBy(['code' => $buyer->getOrderPaymentMethod()]);
        $channel = $order->getChannel();
        if (!$method instanceof PaymentMethodInterface || !$method->isEnabled() || !$channel instanceof ChannelInterface || !$method->hasChannel($channel)) {
            throw new OrderRequestRejected('The payment method of the buyer is not available', \Tmonier\SyliusPunchoutCxmlPlugin\Cxml\CxmlStatus::OPERATION_FAILED);
        }
        foreach ($order->getPayments() as $payment) {
            $payment->setMethod($method);
        }
    }

    private function complete(OrderInterface $order): void
    {
        foreach (self::CHECKOUT as $transition) {
            if ($this->stateMachine->can($order, OrderCheckoutTransitions::GRAPH, $transition)) {
                $this->stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, $transition);
            }
        }
        if ($order->getCheckoutState() !== OrderCheckoutStates::STATE_COMPLETED) {
            throw new OrderRequestRejected('The order could not be completed');
        }
    }
}
