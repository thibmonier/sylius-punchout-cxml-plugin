<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Transfer;

use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\OrderItemUnitInterface;

/**
 * Computes the net unit price (ex-tax) for an order item (ADR-005).
 *
 * Algorithm:
 * 1. Use getFullDiscountedUnitPrice() (includes unit and order-level discounts).
 * 2. If the channel is tax-inclusive, remove the included tax proportion.
 * 3. Return the price as a string with exactly 2 decimal places.
 *
 * The "tax included" flag is read from the buyer configuration; if null, we
 * fall back to the channel setting (taxCalculationStrategy / includedInPrice).
 */
final class NetPriceCalculator
{
    /**
     * @param bool $channelTaxIncluded whether the channel displays prices incl. tax
     */
    public function calculateUnitNetPrice(OrderItemInterface $item, bool $channelTaxIncluded = false): string
    {
        // getFullDiscountedUnitPrice() returns centimes (integer).
        $unitPrice = $item->getFullDiscountedUnitPrice();

        if ($channelTaxIncluded) {
            // Remove the embedded tax.
            // We need to derive the effective tax rate from the first unit's adjustments.
            $units = $item->getUnits();
            $taxAmount = 0;
            $count = 0;

            foreach ($units as $unit) {
                /** @var OrderItemUnitInterface $unit */
                $taxAmount += $this->getTaxAdjustmentTotal($unit);
                ++$count;

                // One unit is sufficient for the rate calculation
                break;
            }

            if ($count > 0 && $taxAmount > 0) {
                // unitPrice (inclusive) = netPrice + taxAmount
                // => netPrice = unitPrice - taxAmount
                $unitPrice -= $taxAmount;
            }
        }

        // Format as decimal string with 2 places (centimes → euros)
        return number_format($unitPrice / 100, 2, '.', '');
    }

    /**
     * Computes the total (sum of quantity × unit net price) as a decimal string.
     *
     * @param list<TransferLine> $lines
     */
    public function computeTotal(array $lines): string
    {
        $total = '0.00';
        foreach ($lines as $line) {
            $lineTotal = (float) $line->unitNetPrice * $line->quantity;
            $total = number_format((float) $total + $lineTotal, 2, '.', '');
        }

        return $total;
    }

    private function getTaxAdjustmentTotal(OrderItemUnitInterface $unit): int
    {
        $total = 0;
        foreach ($unit->getAdjustments() as $adjustment) {
            if ($adjustment->getType() === 'tax') {
                $total += $adjustment->getAmount();
            }
        }

        return $total;
    }
}
