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
 * 2. If a tax adjustment on the first unit is NEUTRAL (isNeutral() = true), the
 *    tax is already embedded in the price (tax-inclusive channel). Subtract it to
 *    obtain the net price.
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
        // getFullDiscountedUnitPrice() returns centimes (integer).
        $unitPrice = $item->getFullDiscountedUnitPrice();

        // Inspect the first unit's tax adjustments.
        // Neutral = tax is embedded in the price (TTC / tax-inclusive).
        // Non-neutral = tax is additional (HT / tax-exclusive).
        $units = $item->getUnits();
        foreach ($units as $unit) {
            /** @var OrderItemUnitInterface $unit */
            $neutralTax = $this->getNeutralTaxAmount($unit);
            if ($neutralTax > 0) {
                // unitPrice (inclusive) = netPrice + embeddedTax
                // => netPrice = unitPrice - embeddedTax
                $unitPrice -= $neutralTax;
            }

            // One unit is sufficient for the rate calculation
            break;
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
