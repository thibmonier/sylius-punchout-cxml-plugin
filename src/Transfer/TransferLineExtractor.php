<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Transfer;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Classification\ItemClassifierInterface;

/**
 * Extracts TransferLine DTOs from a Sylius order (R5: no channelTaxIncluded flag).
 *
 * The NetPriceCalculator auto-detects neutral vs non-neutral tax adjustments.
 */
final readonly class TransferLineExtractor
{
    public function __construct(
        private NetPriceCalculator $netPriceCalculator,
        private ItemClassifierInterface $itemClassifier,
    ) {
    }

    /**
     * @return list<TransferLine>
     */
    public function extract(OrderInterface $order, ?BuyerInterface $buyer = null): array
    {
        $lines = [];

        foreach ($order->getItems() as $item) {
            /** @var OrderItemInterface $item */
            $variant = $item->getVariant();
            if (!$variant instanceof ProductVariantInterface) {
                continue;
            }

            $code = $variant->getCode() ?? '';
            $unitNetPrice = $this->netPriceCalculator->calculateUnitNetPrice($item);
            $currency = $order->getCurrencyCode() ?? 'EUR';
            $description = $item->getProductName() ?? $code;
            $quantity = $item->getQuantity();

            $classification = $this->itemClassifier->classify($variant, $buyer);
            $lines[] = new TransferLine(
                variantCode: $code,
                supplierPartAuxId: 'v1:' . $code,
                description: $description,
                quantity: $quantity,
                unitNetPrice: $unitNetPrice,
                currency: $currency,
                unitOfMeasure: $classification->unit,
                unspsc: $classification->unspsc,
            );
        }

        return $lines;
    }
}
