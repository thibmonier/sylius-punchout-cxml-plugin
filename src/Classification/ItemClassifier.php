<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Classification;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;

/**
 * UNSPSC code: the product's, else the one of its main taxon or the nearest parent, else the buyer
 * default, else "00000000" (unclassified: the cXML DTD requires a Classification). Unit: the
 * product's, else the buyer default (UN/CEFACT, "EA" unless configured).
 */
final readonly class ItemClassifier implements ItemClassifierInterface
{
    public const UNCLASSIFIED = '00000000';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function classify(ProductVariantInterface $variant, ?BuyerInterface $buyer): ItemClassification
    {
        $product = $variant->getProduct();
        $productClassification = $product instanceof \Sylius\Component\Product\Model\ProductInterface ? $this->entityManager->getRepository(ProductClassification::class)->findOneBy(['product' => $product]) : null;

        $unspsc = $productClassification?->getUnspsc();
        $taxon = $product instanceof \Sylius\Component\Core\Model\ProductInterface ? $product->getMainTaxon() : null;
        while ($unspsc === null && $taxon instanceof TaxonInterface) {
            $unspsc = $this->entityManager->getRepository(TaxonClassification::class)->findOneBy(['taxon' => $taxon])?->getUnspsc();
            $parent = $taxon->getParent();
            $taxon = $parent instanceof TaxonInterface ? $parent : null;
        }

        return new ItemClassification(
            $unspsc ?? $buyer?->getDefaultUnspsc() ?? self::UNCLASSIFIED,
            $productClassification?->getUnit() ?? $buyer?->getDefaultUnit() ?? 'EA',
        );
    }
}
