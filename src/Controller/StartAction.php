<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\SessionStarter;
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
        private SessionStarter $sessionStarter,
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

        return $this->sessionStarter->start($session, $request);
    }
}
