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

    #[Test]
    public function it_passes_through_price_when_tax_excluded(): void
    {
        $item = $this->createOrderItem(10000, 1); // 100.00 € HT

        $net = $this->calculator->calculateUnitNetPrice($item, false);

        self::assertSame('100.00', $net);
    }

    #[Test]
    public function it_removes_tax_from_ttc_price_20_percent(): void
    {
        // 120.00 TTC with 20% tax → 20.00 tax per unit → 100.00 net
        $item = $this->createOrderItemWithTax(12000, 2000, 1);

        $net = $this->calculator->calculateUnitNetPrice($item, true);

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

    private function createOrderItem(int $fullDiscountedUnitPrice, int $quantity): OrderItem
    {
        $item = $this->createMock(OrderItem::class);
        $item->method('getFullDiscountedUnitPrice')->willReturn($fullDiscountedUnitPrice);
        $item->method('getQuantity')->willReturn($quantity);
        $item->method('getUnits')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        return $item;
    }

    private function createOrderItemWithTax(
        int $fullDiscountedUnitPrice,
        int $taxAmountPerUnit,
        int $quantity,
    ): OrderItem {
        $adjustment = $this->createMock(Adjustment::class);
        $adjustment->method('getType')->willReturn('tax');
        $adjustment->method('getAmount')->willReturn($taxAmountPerUnit);

        $unit = $this->createMock(OrderItemUnit::class);
        $unit->method('getAdjustments')->willReturn(
            new \Doctrine\Common\Collections\ArrayCollection([$adjustment]),
        );

        $item = $this->createMock(OrderItem::class);
        $item->method('getFullDiscountedUnitPrice')->willReturn($fullDiscountedUnitPrice);
        $item->method('getQuantity')->willReturn($quantity);
        $item->method('getUnits')->willReturn(
            new \Doctrine\Common\Collections\ArrayCollection([$unit]),
        );

        return $item;
    }
}
