<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\EventListener;

use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\CurrentPunchoutSession;

/**
 * US-016: procurement systems (SAP Ariba first) open the PunchOut in an iframe. During a PunchOut
 * session only, the shop pages may be framed by the buyer's origins: X-Frame-Options (sameorigin, set
 * by Sylius) is replaced by a CSP frame-ancestors limited to them. Outside a session nothing changes.
 *
 * `kernel.response` priority -16: after Sylius' X-Frame-Options subscriber (0).
 */
final readonly class FrameHeadersListener
{
    private const ORIGIN = '#^https://([a-z0-9-]+\.)+[a-z][a-z0-9-]+(:\d{1,5})?$#';

    public function __construct(private CurrentPunchoutSession $currentPunchoutSession)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $response = $event->getResponse();
        // Pages with their own policy (the transfer page) keep it.
        if ($response->headers->has('Content-Security-Policy')) {
            return;
        }
        $buyer = $this->currentPunchoutSession->getCurrent()?->getBuyer();
        if (!$buyer instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface) {
            return;
        }

        // Origins are validated by the admin form; filter them again here, whatever wrote them.
        $origins = array_filter($buyer->getIframeOrigins(), static fn (string $origin): bool => 1 === preg_match(self::ORIGIN, $origin));
        $response->headers->remove('X-Frame-Options');
        $response->headers->set('Content-Security-Policy', trim("frame-ancestors 'self' " . implode(' ', $origins)));
    }
}
