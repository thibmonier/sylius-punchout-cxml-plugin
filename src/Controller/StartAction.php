<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Cart\PunchoutCartFactory;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\ShopperAuthenticator;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\StartTokenConsumer;

/**
 * GET /punchout/start/{token}
 *
 * Consumes a single-use start token, authenticates the shopper, creates an
 * isolated PunchOut cart (R1), and redirects the shopper to the shop homepage.
 *
 * ADR-0003: a linked Sylius customer is mandatory.
 * If the buyer has no linked customer, the session is cancelled and a
 * generic 403 error page is returned — no anonymous fallback.
 */
final readonly class StartAction
{
    public function __construct(
        private StartTokenConsumer $tokenConsumer,
        private ShopperAuthenticator $shopperAuthenticator,
        private PunchoutCartFactory $punchoutCartFactory,
        private UrlGeneratorInterface $urlGenerator,
        private EntityManagerInterface $entityManager,
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

            return new Response('', $session instanceof PunchoutSession ? Response::HTTP_OK : Response::HTTP_NOT_FOUND);
        }

        // Consume the token atomically (pending → active)
        $session = $this->tokenConsumer->consume($token);

        if (!$session instanceof PunchoutSession) {
            // Token not found, expired, or already consumed
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        // ADR-0003: authenticate the buyer's linked customer — no anonymous fallback.
        // ShopperAuthenticator throws RuntimeException when the buyer has no linked
        // customer, no shop user, the shop user is disabled, or R7 checks fail.
        try {
            $this->shopperAuthenticator->authenticate($session, $request);
        } catch (\RuntimeException) {
            // Cancel the session so it cannot be reused.
            $session->setStatus(PunchoutSession::STATUS_CANCELLED);
            $this->entityManager->flush();

            return new Response(
                '<html lang="en"><body>PunchOut session could not be established. Contact your administrator.</body></html>',
                Response::HTTP_FORBIDDEN,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        // R1: create an isolated PunchOut cart and link it to the session.
        // The cart is accessed exclusively via $session->getCart() through
        // PunchoutCartContext — it is NOT stored in the channel cart storage
        // to avoid overwriting the shopper's regular cart.
        $buyer = $session->getBuyer();
        $channel = $buyer?->getChannel();
        $customer = $buyer?->getCustomer();

        if ($channel instanceof ChannelInterface && $customer instanceof CustomerInterface) {
            $cart = $this->punchoutCartFactory->create($customer, $channel);
            $this->entityManager->persist($cart);
            $session->setCart($cart);
        }

        $this->entityManager->flush();

        // Redirect to the shop
        $shopUrl = $this->urlGenerator->generate('sylius_shop_homepage');

        return new RedirectResponse($shopUrl);
    }
}
