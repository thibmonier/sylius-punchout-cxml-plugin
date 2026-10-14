<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Classification;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\TaxonInterface;

/** Loads and stores the PunchOut classification of products and taxons edited in the admin. */
final readonly class ClassificationManager
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return array{unspsc: string|null, unit: string|null} */
    public function loadProduct(ProductInterface $product): array
    {
        $classification = $product->getId() === null ? null : $this->entityManager->getRepository(ProductClassification::class)->findOneBy(['product' => $product]);

        return ['unspsc' => $classification?->getUnspsc(), 'unit' => $classification?->getUnit()];
    }

    public function saveProduct(ProductInterface $product, ?string $unspsc, ?string $unit): void
    {
        $classification = $product->getId() === null ? null : $this->entityManager->getRepository(ProductClassification::class)->findOneBy(['product' => $product]);
        if ($unspsc === null && $unit === null) {
            if ($classification !== null) {
                $this->entityManager->remove($classification);
            }

            return;
        }
        $classification ??= new ProductClassification($product);
        $classification->setUnspsc($unspsc);
        $classification->setUnit($unit);
        $this->entityManager->persist($classification);
    }

    public function loadTaxon(TaxonInterface $taxon): ?string
    {
        return $taxon->getId() === null ? null : $this->entityManager->getRepository(TaxonClassification::class)->findOneBy(['taxon' => $taxon])?->getUnspsc();
    }

    public function saveTaxon(TaxonInterface $taxon, ?string $unspsc): void
    {
        $classification = $taxon->getId() === null ? null : $this->entityManager->getRepository(TaxonClassification::class)->findOneBy(['taxon' => $taxon]);
        if ($unspsc === null) {
            if ($classification !== null) {
                $this->entityManager->remove($classification);
            }

            return;
        }
        $classification ??= new TaxonClassification($taxon);
        $classification->setUnspsc($unspsc);
        $this->entityManager->persist($classification);
    }
}
