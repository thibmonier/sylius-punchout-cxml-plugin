<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\OrderMessageWriter;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\PayloadIdGenerator;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\InvalidReturnUrlException;
use Tmonier\SyliusPunchoutCxmlPlugin\Security\ReturnUrlValidator;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\CurrentPunchoutSession;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;
use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\NetPriceCalculator;
use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\TransferLineExtractor;
use Twig\Environment as TwigEnvironment;

/**
 * POST /punchout/transfer
 *
 * Builds a cXML PunchOutOrderMessage from the shopper's PunchOut cart (R2)
 * and posts it back to the buyer's procurement system via a self-submitting
 * HTML form (browser-side POST to the returnUrl).
 *
 * R3: CSRF token required (intent: tmonier_punchout_transfer, field: _csrf_token).
 * R6: returnUrl is re-validated against ReturnUrlValidator before use.
 */
final readonly class TransferAction
{
    public function __construct(
        private CurrentPunchoutSession $currentPunchoutSession,
        private TransferLineExtractor $transferLineExtractor,
        private NetPriceCalculator $netPriceCalculator,
        private OrderMessageWriter $orderMessageWriter,
        private PayloadIdGenerator $payloadIdGenerator,
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokenStorage,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private ReturnUrlValidator $returnUrlValidator,
        private TwigEnvironment $twig,
        private bool $keepTransferredCarts = false,
        private string $supplierDomain = 'NetworkId',
        private string $supplierIdentity = 'supplier',
    ) {
    }

    public function __invoke(Request $request): Response
    {
        // 1. Check active PunchOut session (must be first — 403 even without CSRF)
        $session = $this->currentPunchoutSession->getCurrent();
        if (!$session instanceof PunchoutSession || !$session->isActive()) {
            $forbidden = new Response('No active PunchOut session', Response::HTTP_FORBIDDEN);
            $forbidden->headers->set('Cache-Control', 'no-store');

            return $forbidden;
        }

        // 2. R3: Validate CSRF token
        $csrfToken = new CsrfToken('tmonier_punchout_transfer', $request->request->getString('_csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            $invalid = new Response('Invalid CSRF token', Response::HTTP_UNPROCESSABLE_ENTITY);
            $invalid->headers->set('Cache-Control', 'no-store');

            return $invalid;
        }

        // 3. Check authenticated user
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            $forbidden = new Response('Not authenticated', Response::HTTP_FORBIDDEN);
            $forbidden->headers->set('Cache-Control', 'no-store');

            return $forbidden;
        }

        // 4. R6: Re-validate the returnUrl stored in the session
        $returnUrl = $session->getReturnUrl();
        $buyer = $session->getBuyer();

        try {
            $this->returnUrlValidator->validate($returnUrl, $buyer);
        } catch (InvalidReturnUrlException) {
            $forbidden = new Response('Return URL is no longer valid', Response::HTTP_FORBIDDEN);
            $forbidden->headers->set('Cache-Control', 'no-store');

            return $forbidden;
        }

        // 5. R2: use the session's isolated PunchOut cart (not findLatestCartByChannelAndCustomer)
        $cart = $session->getCart();
        if (!$cart instanceof \Sylius\Component\Core\Model\OrderInterface) {
            return new Response('No PunchOut cart found', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 6. Extract transfer lines (R5: no taxIncluded flag — neutral detection is automatic)
        $lines = $this->transferLineExtractor->extract($cart);
        $total = $this->netPriceCalculator->computeTotal($lines);
        $currency = $cart->getCurrencyCode() ?? 'EUR';
        $payloadId = $this->payloadIdGenerator->generate();

        // 7. R4: use persisted buyer identities for the cXML headers
        $buyerDomain = $session->getFromDomain() ?: 'NetworkId';
        $buyerIdentity = $session->getFromIdentity() ?: 'buyer';

        $xml = $this->orderMessageWriter->write(
            $lines,
            $total,
            $currency,
            $session->getBuyerCookie(),
            $payloadId,
            $session->getOperation(),
            $this->supplierDomain,
            $this->supplierIdentity,
            $buyerDomain,
            $buyerIdentity,
        );

        // Mark session as transferred
        $session->setStatus(PunchoutSession::STATUS_TRANSFERRED);
        $this->entityManager->flush();

        // Optionally empty the cart
        if (!$this->keepTransferredCarts) {
            $this->clearCart($cart);
        }

        // Build a self-submitting form that POSTs the cXML to the returnUrl
        $xmlBase64 = base64_encode($xml);

        // Generate a cryptographically random nonce for the inline script
        $nonce = base64_encode(random_bytes(16));

        // Extract the origin of the returnUrl for the form-action CSP directive
        $parsedReturnUrl = parse_url($returnUrl);
        $returnUrlOrigin = sprintf(
            '%s://%s%s',
            $parsedReturnUrl['scheme'] ?? 'https',
            $parsedReturnUrl['host'] ?? '',
            isset($parsedReturnUrl['port']) ? ':' . $parsedReturnUrl['port'] : '',
        );

        $html = $this->twig->render('@TmonierSyliusPunchoutCxmlPlugin/punchout/transfer.html.twig', [
            'returnUrl' => $returnUrl,
            'xmlBase64' => $xmlBase64,
            'nonce' => $nonce,
        ]);

        $response = new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set(
            'Content-Security-Policy',
            sprintf(
                "default-src 'none'; script-src 'nonce-%s'; form-action %s; base-uri 'none'",
                $nonce,
                $returnUrlOrigin,
            ),
        );

        return $response;
    }

    private function clearCart(\Sylius\Component\Core\Model\OrderInterface $cart): void
    {
        foreach ($cart->getItems() as $item) {
            $cart->removeItem($item);
            $this->entityManager->remove($item);
        }
        $this->entityManager->flush();
    }
}
