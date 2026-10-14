<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Unit\Transfer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\NetPriceCalculator;
use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\TransferLine;

final class NetPriceCalculatorTest extends TestCase
{
    private NetPriceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new NetPriceCalculator();
    }

    /**
     * R5: non-neutral adjustments mean the price is already net (tax excluded).
     * The calculator must NOT strip non-neutral tax adjustments.
     */
    #[Test]
    public function it_passes_through_price_when_tax_excluded(): void
    {
        // 120.00 EUR with non-neutral tax adjustment (tax excluded from unit price)
        $item = $this->createOrderItemWithNonNeutralTax(12000, 2000);

        $net = $this->calculator->calculateUnitNetPrice($item);

        self::assertSame('120.00', $net);
    }

    /**
     * R5: neutral adjustments mean the price is tax-inclusive (TTC).
     * The calculator MUST strip neutral tax adjustments to get the net price.
     */
    #[Test]
    public function it_removes_tax_from_ttc_price_20_percent(): void
    {
        // 120.00 TTC with neutral tax adjustment of 20.00 → net = 100.00
        $item = $this->createOrderItemWithNeutralTax(12000, 2000);

        $net = $this->calculator->calculateUnitNetPrice($item);

        self::assertSame('100.00', $net);
    }

    #[Test]
    public function it_computes_total_for_multiple_lines(): void
    {
        $lines = [
            new TransferLine('A', 'v1:A', 'A', 3, '100.00', 'EUR', 'EA'),
            new TransferLine('B', 'v1:B', 'B', 2, '50.00', 'EUR', 'EA'),
        ];

        $total = $this->calculator->computeTotal($lines);

        self::assertSame('400.00', $total); // 3×100 + 2×50 = 400
    }

    #[Test]
    public function it_returns_zero_for_empty_lines(): void
    {
        $total = $this->calculator->computeTotal([]);

        self::assertSame('0.00', $total);
    }

    #[Test]
    public function it_passes_through_price_when_no_adjustments(): void
    {
        $item = $this->createOrderItem(10000, 1); // 100.00 € no adjustments

        $net = $this->calculator->calculateUnitNetPrice($item);

        self::assertSame('100.00', $net);
    }

    /** Sylius spreads the line tax over the units (1.66 / 1.67 / 1.67): the net price uses the whole line. */
    #[Test]
    public function it_averages_the_included_tax_of_all_units(): void
    {
        $units = [];
        foreach ([166, 167, 167] as $tax) {
            $adjustment = $this->createMock(Adjustment::class);
            $adjustment->method('getType')->willReturn('tax');
            $adjustment->method('getAmount')->willReturn($tax);
            $adjustment->method('isNeutral')->willReturn(true);
            $unit = $this->createMock(OrderItemUnit::class);
            $unit->method('getAdjustments')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([$adjustment]));
            $units[] = $unit;
        }
        $item = $this->createMock(OrderItem::class);
        $item->method('getFullDiscountedUnitPrice')->willReturn(1000);
        $item->method('getQuantity')->willReturn(3);
        $item->method('getUnits')->willReturn(new \Doctrine\Common\Collections\ArrayCollection($units));

        self::assertSame('8.33', $this->calculator->calculateUnitNetPrice($item));
    }

    private function createOrderItem(int $fullDiscountedUnitPrice, int $quantity): OrderItem
    {
        $item = $this->createMock(OrderItem::class);
        $item->method('getFullDiscountedUnitPrice')->willReturn($fullDiscountedUnitPrice);
        $item->method('getQuantity')->willReturn($quantity);
        $item->method('getUnits')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        return $item;
    }

    /**
     * Creates an item with a NEUTRAL tax adjustment (tax-inclusive pricing).
     * Neutral = the adjustment does NOT add to the total; it just records the embedded tax.
     */
    private function createOrderItemWithNeutralTax(
        int $fullDiscountedUnitPrice,
        int $taxAmountPerUnit,
    ): OrderItem {
        $adjustment = $this->createMock(Adjustment::class);
        $adjustment->method('getType')->willReturn('tax');
        $adjustment->method('getAmount')->willReturn($taxAmountPerUnit);
        $adjustment->method('isNeutral')->willReturn(true);

        $unit = $this->createMock(OrderItemUnit::class);
        $unit->method('getAdjustments')->willReturn(
            new \Doctrine\Common\Collections\ArrayCollection([$adjustment]),
        );

        $item = $this->createMock(OrderItem::class);
        $item->method('getFullDiscountedUnitPrice')->willReturn($fullDiscountedUnitPrice);
        $item->method('getQuantity')->willReturn(1);
        $item->method('getUnits')->willReturn(
            new \Doctrine\Common\Collections\ArrayCollection([$unit]),
        );

        return $item;
    }

    /**
     * Creates an item with a NON-NEUTRAL tax adjustment (tax-exclusive pricing).
     * Non-neutral = the adjustment adds to the total; tax is computed on top of the unit price.
     */
    private function createOrderItemWithNonNeutralTax(
        int $fullDiscountedUnitPrice,
        int $taxAmountPerUnit,
    ): OrderItem {
        $adjustment = $this->createMock(Adjustment::class);
        $adjustment->method('getType')->willReturn('tax');
        $adjustment->method('getAmount')->willReturn($taxAmountPerUnit);
        $adjustment->method('isNeutral')->willReturn(false);

        $unit = $this->createMock(OrderItemUnit::class);
        $unit->method('getAdjustments')->willReturn(
            new \Doctrine\Common\Collections\ArrayCollection([$adjustment]),
        );

        $item = $this->createMock(OrderItem::class);
        $item->method('getFullDiscountedUnitPrice')->willReturn($fullDiscountedUnitPrice);
        $item->method('getQuantity')->willReturn(1);
        $item->method('getUnits')->willReturn(
            new \Doctrine\Common\Collections\ArrayCollection([$unit]),
        );

        return $item;
    }
}
