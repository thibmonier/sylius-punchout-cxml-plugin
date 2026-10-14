<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Transfer;

use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\OrderItemUnitInterface;

/**
 * Computes the net unit price (ex-tax) for an order item (ADR-005, R5).
 *
 * Algorithm:
 * 1. Use getFullDiscountedUnitPrice() (includes unit and order-level discounts).
 * 2. Subtract the NEUTRAL tax adjustments of all the units (tax included in the
 *    price, tax-inclusive channel) and divide by the quantity.
 *    A non-neutral tax adjustment means the tax is added on top: the price is
 *    already net — do not subtract anything.
 * 3. Return the price as a string with exactly 2 decimal places.
 *
 * The detection is automatic: no external "taxIncluded" flag is needed.
 */
final class NetPriceCalculator
{
    public function calculateUnitNetPrice(OrderItemInterface $item): string
    {
        $quantity = max(1, $item->getQuantity());

        // Sylius spreads the tax of a line over its units (e.g. 1.66 / 1.67 / 1.67): remove the tax
        // included in the price (neutral adjustments) of the whole line, then divide by the quantity.
        $includedTax = 0;
        foreach ($item->getUnits() as $unit) {
            /** @var OrderItemUnitInterface $unit */
            $includedTax += $this->getNeutralTaxAmount($unit);
        }
        $netUnitPrice = (int) round(($item->getFullDiscountedUnitPrice() * $quantity - $includedTax) / $quantity);

        return number_format($netUnitPrice / 100, 2, '.', '');
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

    /**
     * Returns the sum of NEUTRAL tax adjustments on a unit (centimes).
     *
     * Neutral adjustments record embedded tax (tax-inclusive pricing).
     * Non-neutral adjustments represent additional tax (tax-exclusive).
     */
    private function getNeutralTaxAmount(OrderItemUnitInterface $unit): int
    {
        $total = 0;
        foreach ($unit->getAdjustments() as $adjustment) {
            if ($adjustment->getType() === 'tax' && $adjustment->isNeutral()) {
                $total += $adjustment->getAmount();
            }
        }

        return $total;
    }
}
