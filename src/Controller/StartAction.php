<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\CurrentPunchoutSession;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\ShopperAuthenticator;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\StartTokenConsumer;

/**
 * GET /punchout/start/{token}
 *
 * Consumes a single-use start token, authenticates the shopper and
 * redirects them to the shop homepage.
 */
final readonly class StartAction
{
    public function __construct(
        private StartTokenConsumer $tokenConsumer,
        private ShopperAuthenticator $shopperAuthenticator,
        private CurrentPunchoutSession $currentPunchoutSession,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request, string $token): Response
    {
        // HEAD and browser prefetch: just check validity, do NOT consume
        $isPrefetch = $request->isMethod('HEAD') ||
            $request->headers->get('Sec-Purpose') === 'prefetch' ||
            $request->headers->get('Purpose') === 'prefetch';

        if ($isPrefetch) {
            $session = $this->tokenConsumer->findValid($token);

            return new Response('', $session instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession ? Response::HTTP_OK : Response::HTTP_NOT_FOUND);
        }

        // Consume the token atomically (pending → active)
        $session = $this->tokenConsumer->consume($token);

        if (!$session instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession) {
            // Token not found, expired, or already consumed
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        // Store the punchout session in the Symfony session
        $this->currentPunchoutSession->set($session);

        // Authenticate the buyer as a Sylius shop customer.
        // A buyer without a linked customer or shop user proceeds as an anonymous shopper.
        $buyer = $session->getBuyer();
        if ($buyer instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface) {
            try {
                $this->shopperAuthenticator->authenticate($session, $request);
            } catch (\RuntimeException) {
                // Buyer has no linked customer or shop user — continue as anonymous shopper.
            }
        }

        // Redirect to the shop
        $shopUrl = $this->urlGenerator->generate('sylius_shop_homepage');

        return new RedirectResponse($shopUrl);
    }
}
