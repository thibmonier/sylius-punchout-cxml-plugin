<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\CurrentPunchoutSession;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;

/**
 * POST /punchout/cancel
 *
 * Marks the current PunchOut session as cancelled and redirects the shopper
 * back to the buyer's return URL (empty transfer — the procurement system
 * typically ignores the redirect target but some implementations expect it).
 */
final readonly class CancelAction
{
    public function __construct(
        private CurrentPunchoutSession $currentPunchoutSession,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $session = $this->currentPunchoutSession->getCurrent();
        if ($session instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession && $session->isActive()) {
            $session->setStatus(PunchoutSession::STATUS_CANCELLED);
            $this->entityManager->flush();

            $returnUrl = $session->getReturnUrl();
            $this->currentPunchoutSession->clear();

            // Redirect back to the buyer's procurement system
            return new RedirectResponse($returnUrl);
        }
        // No active session → redirect to shop homepage
        $this->currentPunchoutSession->clear();
        $shopUrl = $this->urlGenerator->generate('sylius_shop_homepage');

        return new RedirectResponse($shopUrl);
    }
}
