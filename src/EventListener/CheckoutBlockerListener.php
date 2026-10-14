<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\EventListener;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\CurrentPunchoutSession;

/**
 * No Sylius order is placed during a PunchOut session: the cart goes back to the procurement system,
 * where it is approved and ordered. The checkout and order routes lead back to the cart.
 *
 * `kernel.request` priority 4: after the firewall (8) and the router (32), before the checkout resolver (0).
 */
final readonly class CheckoutBlockerListener
{
    public function __construct(
        private CurrentPunchoutSession $currentPunchoutSession,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->getString('_route');
        if (!$event->isMainRequest() || !$this->isCheckoutRoute($route) || !$this->currentPunchoutSession->getCurrent() instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession) {
            return;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('info', 'tmonier_punchout.ui.session.checkout_blocked');
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('sylius_shop_cart_summary', ['_locale' => $request->getLocale()])));
    }

    private function isCheckoutRoute(string $route): bool
    {
        return $route === 'sylius_shop_cart_checkout' ||
            str_starts_with($route, 'sylius_shop_checkout_') ||
            str_starts_with($route, 'sylius_shop_order_');
    }
}
