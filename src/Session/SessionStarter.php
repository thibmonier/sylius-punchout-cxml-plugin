<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Session;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Cart\CartRebuilder;
use Tmonier\SyliusPunchoutCxmlPlugin\Cart\PunchoutCartFactory;

/**
 * Opens an active PunchOut session in the shopper's browser (cXML start URL or OCI entry): logs in
 * the buyer's customer (ADR-0003, no anonymous fallback), gives the session its isolated cart (rebuilt
 * from the requisition for edit) and redirects to the shop.
 */
final readonly class SessionStarter
{
    /** Part numbers of an edited requisition that are no longer sold (US-022). */
    public const FLASH_UNAVAILABLE = 'tmonier_punchout_unavailable';

    public function __construct(
        private ShopperAuthenticator $shopperAuthenticator,
        private PunchoutCartFactory $punchoutCartFactory,
        private CartRebuilder $cartRebuilder,
        private UrlGeneratorInterface $urlGenerator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function start(PunchoutSession $session, Request $request): Response
    {
        try {
            $this->shopperAuthenticator->authenticate($session, $request);
        } catch (\RuntimeException) {
            // The session can never be used.
            $session->setStatus(PunchoutSession::STATUS_CANCELLED);
            $this->entityManager->flush();

            return new Response(
                '<html lang="en"><body>PunchOut session could not be established. Contact your administrator.</body></html>',
                Response::HTTP_FORBIDDEN,
                ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'],
            );
        }

        // Isolated PunchOut cart, served by PunchoutCartContext only (never the Sylius cart storage).
        $buyer = $session->getBuyer();
        $channel = $buyer?->getChannel();
        $customer = $buyer?->getCustomer();
        if ($channel instanceof ChannelInterface && $customer instanceof CustomerInterface) {
            $cart = $this->punchoutCartFactory->create($customer, $channel);
            $this->entityManager->persist($cart);
            $session->setCart($cart);

            // US-022: an edited requisition comes back as the cart.
            if ($session->getOperation() === 'edit') {
                $leftOut = $this->cartRebuilder->rebuild($cart, $session->getItemsOut());
                $httpSession = $request->getSession();
                if ($leftOut !== [] && $httpSession instanceof FlashBagAwareSessionInterface) {
                    $httpSession->getFlashBag()->set(self::FLASH_UNAVAILABLE, $leftOut);
                }
            }
        }
        $this->entityManager->flush();

        $locale = $channel?->getDefaultLocale()?->getCode() ?? $request->getLocale();
        $response = new RedirectResponse(match ($session->getOperation()) {
            'edit' => $this->urlGenerator->generate('sylius_shop_cart_summary', ['_locale' => $locale]),
            'inspect' => $this->urlGenerator->generate('tmonier_punchout_inspect', ['_locale' => $locale]),
            default => $this->urlGenerator->generate('sylius_shop_homepage', ['_locale' => $locale]),
        }, Response::HTTP_SEE_OTHER);
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
