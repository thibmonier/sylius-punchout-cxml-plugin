<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Product\Repository\ProductVariantRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Test-only cart endpoints: they go through the Sylius composite cart context, exactly like the
 * shop's add-to-cart, so functional tests can prove which cart is current in a real request.
 *
 * NEVER LOADED IN PRODUCTION (TestApplication routes only).
 */
final readonly class CartTestController
{
    /**
     * @param FactoryInterface<OrderItemInterface> $orderItemFactory
     * @param ProductVariantRepositoryInterface<ProductVariantInterface> $variantRepository
     */
    public function __construct(
        private CartContextInterface $cartContext,
        private FactoryInterface $orderItemFactory,
        private OrderItemQuantityModifierInterface $quantityModifier,
        private OrderModifierInterface $orderModifier,
        private OrderProcessorInterface $orderProcessor,
        private ProductVariantRepositoryInterface $variantRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function show(): JsonResponse
    {
        return new JsonResponse($this->describe($this->cartContext->getCart()));
    }

    public function add(Request $request): JsonResponse
    {
        $variant = $this->variantRepository->findOneBy(['code' => $request->request->getString('variant')]);
        if (!$variant instanceof ProductVariantInterface) {
            return new JsonResponse(['error' => 'unknown variant'], 404);
        }

        $cart = $this->cartContext->getCart();
        $item = $this->orderItemFactory->createNew();
        $item->setVariant($variant);
        $this->quantityModifier->modify($item, max(1, $request->request->getInt('quantity', 1)));
        $this->orderModifier->addToOrder($cart, $item);
        $this->orderProcessor->process($cart);
        $this->entityManager->persist($cart);
        $this->entityManager->flush();

        return new JsonResponse($this->describe($cart));
    }

    /** @return array{id: int|null, items: list<array{variant: string|null, quantity: int}>} */
    private function describe(\Sylius\Component\Order\Model\OrderInterface $cart): array
    {
        $items = [];
        foreach ($cart->getItems() as $item) {
            $items[] = [
                'variant' => $item instanceof OrderItemInterface ? $item->getVariant()?->getCode() : null,
                'quantity' => $item->getQuantity(),
            ];
        }

        return ['id' => $cart instanceof OrderInterface ? $cart->getId() : null, 'items' => $items];
    }
}
