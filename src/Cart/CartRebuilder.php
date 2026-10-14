<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cart;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Product\Repository\ProductVariantRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;

/**
 * Re-punchout (US-022): rebuilds a cart from the ItemOut of a requisition — not from the cart of the
 * previous transfer, which may have been deleted. The variant comes from SupplierPartAuxiliaryID
 * ("v1:<variant code>", written by the transfer) or else SupplierPartID; items no longer sold on the
 * channel are left out and reported.
 */
final readonly class CartRebuilder
{
    private const MAX_QUANTITY = 9999;

    /**
     * @param FactoryInterface<OrderItemInterface> $orderItemFactory
     * @param ProductVariantRepositoryInterface<ProductVariantInterface> $variantRepository
     */
    public function __construct(
        private ProductVariantRepositoryInterface $variantRepository,
        private FactoryInterface $orderItemFactory,
        private OrderItemQuantityModifierInterface $quantityModifier,
        private OrderModifierInterface $orderModifier,
        private OrderProcessorInterface $orderProcessor,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $itemsOut
     *
     * @return list<string> the part numbers left out
     */
    public function rebuild(OrderInterface $cart, array $itemsOut): array
    {
        $leftOut = [];
        foreach ($itemsOut as $itemOut) {
            $code = self::variantCode($itemOut);
            $variant = $code === '' ? null : $this->variantRepository->findOneBy(['code' => $code]);
            $quantity = is_int($itemOut['quantity'] ?? null) ? min($itemOut['quantity'], self::MAX_QUANTITY) : 0;
            if (!$variant instanceof ProductVariantInterface || $quantity < 1 || !$this->isSold($variant, $cart->getChannel())) {
                $leftOut[] = $code !== '' ? $code : '?';

                continue;
            }

            $item = $this->orderItemFactory->createNew();
            $item->setVariant($variant);
            $this->quantityModifier->modify($item, $quantity);
            $this->orderModifier->addToOrder($cart, $item);
        }
        $this->orderProcessor->process($cart);

        return $leftOut;
    }

    /** @param array<string, mixed> $itemOut */
    public static function variantCode(array $itemOut): string
    {
        $aux = is_string($itemOut['supplierPartAuxId'] ?? null) ? $itemOut['supplierPartAuxId'] : '';
        if (str_starts_with($aux, 'v1:')) {
            return substr($aux, 3);
        }

        return is_string($itemOut['supplierPartId'] ?? null) ? $itemOut['supplierPartId'] : '';
    }

    private function isSold(ProductVariantInterface $variant, ?ChannelInterface $channel): bool
    {
        $product = $variant->getProduct();

        return $channel instanceof ChannelInterface &&
            $variant->isEnabled() &&
            $product instanceof \Sylius\Component\Core\Model\ProductInterface &&
            $product->isEnabled() &&
            $product->hasChannel($channel) &&
            $variant->getChannelPricingForChannel($channel)?->getPrice() !== null;
    }
}
