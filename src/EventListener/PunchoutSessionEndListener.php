<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\EventListener;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\CurrentPunchoutSession;

/**
 * A shopper logged in by a PunchOut session uses the buyer's shared customer account: once the
 * session is over (expired, ended elsewhere), their next request goes through the shop logout instead
 * of staying on that account with the Sylius checkout back.
 *
 * `kernel.request` priority 5: after the firewall (8), before the checkout blocker (4).
 */
final readonly class PunchoutSessionEndListener
{
    public function __construct(
        private CurrentPunchoutSession $currentPunchoutSession,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->currentPunchoutSession->findEnded() instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession) {
            return;
        }

        $this->currentPunchoutSession->clear();
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('sylius_shop_logout', ['_locale' => $event->getRequest()->getLocale()])));
    }
}
