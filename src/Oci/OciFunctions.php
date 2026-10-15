<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Oci;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Cart\CartRebuilder;
use Tmonier\SyliusPunchoutCxmlPlugin\Cart\PunchoutCartFactory;
use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\TransferLine;
use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\TransferLineExtractor;

/**
 * OCI functions on an item known by its PRODUCTID (the EXT_PRODUCT_ID sent by the transfer, "v1:<variant
 * code>", or the variant code):
 * - VALIDATE (US-031): the item at the buyer's current price, computed on a cart that is never saved;
 * - DETAIL (US-032): the URL of the product page.
 */
final readonly class OciFunctions
{
    private const MAX_QUANTITY = 9999;

    public function __construct(
        private CartRebuilder $cartRebuilder,
        private PunchoutCartFactory $cartFactory,
        private TransferLineExtractor $transferLineExtractor,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** @return list<TransferLine> the item to send back, none when it is not sold on the buyer's channel */
    public function validate(BuyerInterface $buyer, string $productId, int $quantity): array
    {
        $customer = $buyer->getCustomer();
        $channel = $buyer->getChannel();
        if (!$this->variant($productId, $channel) instanceof \Sylius\Component\Core\Model\ProductVariantInterface || !$customer instanceof CustomerInterface || !$channel instanceof ChannelInterface) {
            return [];
        }
        $cart = $this->cartFactory->create($customer, $channel);
        $this->cartRebuilder->rebuild($cart, [['supplierPartAuxId' => 'v1:' . $this->variantCode($productId), 'quantity' => max(1, min($quantity, self::MAX_QUANTITY))]]);

        return $this->transferLineExtractor->extract($cart, $buyer);
    }

    /** The product page of the item, null when it is not sold on the buyer's channel. */
    public function detailUrl(BuyerInterface $buyer, string $productId): ?string
    {
        $channel = $buyer->getChannel();
        $locale = $channel?->getDefaultLocale()?->getCode() ?? 'en_US';
        $slug = $this->variant($productId, $channel)?->getProduct()?->getTranslation($locale)->getSlug();

        return $slug === null ? null : $this->urlGenerator->generate('sylius_shop_product_show', ['slug' => $slug, '_locale' => $locale]);
    }

    private function variant(string $productId, ?ChannelInterface $channel): ?ProductVariantInterface
    {
        $code = $this->variantCode($productId);

        return $this->cartRebuilder->soldVariants([$code], $channel)[$code] ?? null;
    }

    private function variantCode(string $productId): string
    {
        return CartRebuilder::variantCode(['supplierPartAuxId' => $productId, 'supplierPartId' => $productId]);
    }
}
