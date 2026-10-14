<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Controller;

use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Product\Repository\ProductVariantRepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Cart\CartRebuilder;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\CurrentPunchoutSession;
use Twig\Environment;

/**
 * GET /{_locale}/punchout/inspect (US-023): the requisition, read only. "Back" sends a
 * PunchOutOrderMessage without items (operationAllowed="inspect").
 */
final readonly class InspectAction
{
    /** @param ProductVariantRepositoryInterface<ProductVariantInterface> $variantRepository */
    public function __construct(
        private CurrentPunchoutSession $currentPunchoutSession,
        private ProductVariantRepositoryInterface $variantRepository,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
    ) {
    }

    public function __invoke(): Response
    {
        $session = $this->currentPunchoutSession->getCurrent();
        if (!$session instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession || $session->getOperation() !== 'inspect') {
            return new RedirectResponse($this->urlGenerator->generate('sylius_shop_homepage'));
        }

        $items = [];
        foreach ($session->getItemsOut() as $itemOut) {
            $code = CartRebuilder::variantCode($itemOut);
            $variant = $code === '' ? null : $this->variantRepository->findOneBy(['code' => $code]);
            $items[] = [
                'code' => $code,
                'name' => $variant instanceof ProductVariantInterface ? ($variant->getProduct()?->getName() ?? $variant->getName()) : null,
                'quantity' => is_int($itemOut['quantity'] ?? null) ? $itemOut['quantity'] : 0,
            ];
        }

        return new Response($this->twig->render('@TmonierSyliusPunchoutCxmlPlugin/shop/punchout/inspect.html.twig', [
            'punchout_session' => $session,
            'items' => $items,
        ]));
    }
}
