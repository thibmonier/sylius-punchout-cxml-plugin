<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\CustomerInterface as CoreCustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\OrderMessageWriter;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\PayloadIdGenerator;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\CurrentPunchoutSession;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;
use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\NetPriceCalculator;
use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\TransferLineExtractor;
use Twig\Environment as TwigEnvironment;

/**
 * POST /punchout/transfer
 *
 * Builds a cXML PunchOutOrderMessage from the shopper's active cart
 * and posts it back to the buyer's procurement system via a self-submitting
 * HTML form (browser-side POST to the returnUrl).
 */
final readonly class TransferAction
{
    public function __construct(
        private CurrentPunchoutSession $currentPunchoutSession,
        private OrderRepositoryInterface $orderRepository,
        private TransferLineExtractor $transferLineExtractor,
        private NetPriceCalculator $netPriceCalculator,
        private OrderMessageWriter $orderMessageWriter,
        private PayloadIdGenerator $payloadIdGenerator,
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokenStorage,
        private TwigEnvironment $twig,
        private bool $keepTransferredCarts = false,
    ) {
    }

    public function __invoke(): Response
    {
        $session = $this->currentPunchoutSession->getCurrent();
        if (!$session instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession || !$session->isActive()) {
            $forbidden = new Response('No active PunchOut session', Response::HTTP_FORBIDDEN);
            $forbidden->headers->set('Cache-Control', 'no-store');

            return $forbidden;
        }
        // Find the current cart for the authenticated shopper
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            return new Response('Not authenticated', Response::HTTP_FORBIDDEN);
        }
        $cart = $this->resolveCart($user, $session);
        if (!$cart instanceof \Sylius\Component\Core\Model\OrderInterface) {
            return new Response('No cart found', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // Determine if channel is tax-inclusive (read from buyer configuration, ADR-005)
        $taxIncluded = $this->isTaxIncluded($session);
        $lines = $this->transferLineExtractor->extract($cart, $taxIncluded);

        $total = $this->netPriceCalculator->computeTotal($lines);
        $currency = $cart->getCurrencyCode() ?? 'EUR';
        $payloadId = $this->payloadIdGenerator->generate();
        $xml = $this->orderMessageWriter->write(
            $lines,
            $total,
            $currency,
            $session->getBuyerCookie(),
            $payloadId,
            $session->getOperation(),
        );
        // Mark session as transferred
        $session->setStatus(PunchoutSession::STATUS_TRANSFERRED);
        $this->entityManager->flush();
        // Optionally empty the cart
        if (!$this->keepTransferredCarts) {
            $this->clearCart($cart);
        }
        // Build a self-submitting form that POSTs the cXML to the returnUrl
        // cXML-base64 is the standard field name for cXML PunchOut transfer
        $returnUrl = $session->getReturnUrl();
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

    private function resolveCart(UserInterface $user, PunchoutSession $session): ?OrderInterface
    {
        $channel = $session->getBuyer()?->getChannel();
        if (!$channel instanceof \Sylius\Component\Core\Model\ChannelInterface) {
            return null;
        }

        // Try customer-level lookup first (most common path)
        if (!$user instanceof ShopUserInterface) {
            return null;
        }

        $customer = $user->getCustomer();
        if (!$customer instanceof CoreCustomerInterface) {
            return null;
        }

        return $this->orderRepository->findLatestCartByChannelAndCustomer($channel, $customer);
    }

    /**
     * Resolves whether the channel displays tax-inclusive prices (ADR-005).
     *
     * Priority: buyer.taxIncluded override → false (channel strategy deferred to sprint 2).
     */
    private function isTaxIncluded(PunchoutSession $session): bool
    {
        $buyer = $session->getBuyer();
        if ($buyer instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface) {
            $override = $buyer->getTaxIncluded();
            if ($override !== null) {
                return $override;
            }
        }

        // Channel-level strategy (e.g. tax_calculation_strategy) deferred to sprint 2
        return false;
    }

    private function clearCart(OrderInterface $cart): void
    {
        foreach ($cart->getItems() as $item) {
            $cart->removeItem($item);
            $this->entityManager->remove($item);
        }
        $this->entityManager->flush();
    }
}
