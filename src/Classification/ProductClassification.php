<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Classification;

use Sylius\Component\Core\Model\ProductInterface;

/** UNSPSC code and unit of measure of a product, overriding its taxon and the buyer defaults (US-005). */
class ProductClassification
{
    private ?int $id = null;

    public function __construct(
        private readonly ProductInterface $product,
        private ?string $unspsc = null,
        private ?string $unit = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): ProductInterface
    {
        return $this->product;
    }

    public function getUnspsc(): ?string
    {
        return $this->unspsc;
    }

    public function setUnspsc(?string $unspsc): void
    {
        $this->unspsc = $unspsc;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): void
    {
        $this->unit = $unit;
    }
}
