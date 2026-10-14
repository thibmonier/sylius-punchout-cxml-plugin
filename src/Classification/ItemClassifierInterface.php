<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Classification;

use Sylius\Component\Core\Model\ProductVariantInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;

/**
 * Extension point (US-005): decorate or replace the `tmonier_punchout.classification.item_classifier`
 * service to take codes from product attributes, a PIM, a buyer-specific mapping…
 */
interface ItemClassifierInterface
{
    public function classify(ProductVariantInterface $variant, ?BuyerInterface $buyer): ItemClassification;
}
