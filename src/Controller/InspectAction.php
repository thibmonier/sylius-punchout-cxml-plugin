<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Controller;

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
    public function __construct(
        private CurrentPunchoutSession $currentPunchoutSession,
        private CartRebuilder $cartRebuilder,
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

        $variants = $this->cartRebuilder->soldVariants(
            array_map(CartRebuilder::variantCode(...), $session->getItemsOut()),
            $session->getBuyer()?->getChannel(),
        );
        $items = [];
        foreach ($session->getItemsOut() as $itemOut) {
            $code = CartRebuilder::variantCode($itemOut);
            $variant = $variants[$code] ?? null;
            $items[] = [
                'code' => $code,
                // Only items sold on the channel are named: no catalogue disclosure through guessed codes.
                'name' => $variant?->getProduct()?->getName() ?? $variant?->getName(),
                'quantity' => is_int($itemOut['quantity'] ?? null) ? $itemOut['quantity'] : 0,
            ];
        }

        return new Response($this->twig->render('@TmonierSyliusPunchoutCxmlPlugin/shop/punchout/inspect.html.twig', [
            'punchout_session' => $session,
            'items' => $items,
        ]));
    }
}
