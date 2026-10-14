<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Classification;

use Sylius\Component\Core\Model\TaxonInterface;

/** UNSPSC code of a taxon, inherited by its products and sub-taxons (US-005). */
class TaxonClassification
{
    private ?int $id = null;

    public function __construct(
        private readonly TaxonInterface $taxon,
        private ?string $unspsc = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTaxon(): TaxonInterface
    {
        return $this->taxon;
    }

    public function getUnspsc(): ?string
    {
        return $this->unspsc;
    }

    public function setUnspsc(?string $unspsc): void
    {
        $this->unspsc = $unspsc;
    }
}
