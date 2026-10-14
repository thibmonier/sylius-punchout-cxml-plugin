<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Transfer;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * Extracts TransferLine DTOs from a Sylius order.
 */
final readonly class TransferLineExtractor
{
    public function __construct(private NetPriceCalculator $netPriceCalculator)
    {
    }

    /**
     * @param bool $channelTaxIncluded whether the channel shows tax-inclusive prices
     *
     * @return list<TransferLine>
     */
    public function extract(OrderInterface $order, bool $channelTaxIncluded = false): array
    {
        $lines = [];

        foreach ($order->getItems() as $item) {
            /** @var OrderItemInterface $item */
            $variant = $item->getVariant();
            if (!$variant instanceof ProductVariantInterface) {
                continue;
            }

            $code = $variant->getCode() ?? '';
            $unitNetPrice = $this->netPriceCalculator->calculateUnitNetPrice($item, $channelTaxIncluded);
            $currency = $order->getCurrencyCode() ?? 'EUR';
            $description = $item->getProductName() ?? $code;
            $quantity = $item->getQuantity();

            $lines[] = new TransferLine(
                variantCode: $code,
                supplierPartAuxId: 'v1:' . $code,
                description: $description,
                quantity: $quantity,
                unitNetPrice: $unitNetPrice,
                currency: $currency,
                unitOfMeasure: 'EA',
            );
        }

        return $lines;
    }
}
