<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\InvalidReturnUrlException;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\ReturnUrlValidator;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\CurrentPunchoutSession;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;

/**
 * POST /punchout/cancel
 *
 * Marks the current PunchOut session as cancelled and redirects the shopper
 * back to the buyer's return URL (empty transfer — the procurement system
 * typically ignores the redirect target but some implementations expect it).
 *
 * R3: CSRF token required (intent: tmonier_punchout_cancel, field: _csrf_token).
 * R6: returnUrl is re-validated against ReturnUrlValidator before redirect.
 */
final readonly class CancelAction
{
    public function __construct(
        private CurrentPunchoutSession $currentPunchoutSession,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private ReturnUrlValidator $returnUrlValidator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $session = $this->currentPunchoutSession->getCurrent();

        if (!$session instanceof PunchoutSession || !$session->isActive()) {
            $this->currentPunchoutSession->clear();

            return new RedirectResponse($this->urlGenerator->generate('sylius_shop_homepage'));
        }

        // R3: Validate CSRF token
        $csrfToken = new CsrfToken('tmonier_punchout_cancel', $request->request->getString('_csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            return new Response('Invalid CSRF token', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $returnUrl = $session->getReturnUrl();
        $buyer = $session->getBuyer();

        // R6: Re-validate the returnUrl before redirecting to it
        try {
            $this->returnUrlValidator->validate($returnUrl, $buyer);
        } catch (InvalidReturnUrlException) {
            // If returnUrl is no longer valid, fall back to shop homepage
            $session->setStatus(PunchoutSession::STATUS_CANCELLED);
            $this->entityManager->flush();
            $this->currentPunchoutSession->clear();

            return new RedirectResponse($this->urlGenerator->generate('sylius_shop_homepage'));
        }

        $session->setStatus(PunchoutSession::STATUS_CANCELLED);
        $this->entityManager->flush();
        $this->currentPunchoutSession->clear();

        // Redirect back to the buyer's procurement system
        return new RedirectResponse($returnUrl);
    }
}
