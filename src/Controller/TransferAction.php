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
        private bool $keepTransferredCarts = false,
    ) {
    }

    public function __invoke(): Response
    {
        $session = $this->currentPunchoutSession->getCurrent();
        if (!$session instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession || !$session->isActive()) {
            return new Response('No active PunchOut session', Response::HTTP_FORBIDDEN);
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
        // Determine if channel is tax-inclusive
        $cart->getChannel();
        $taxIncluded = $this->isTaxIncluded();
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
        $returnUrl = htmlspecialchars($session->getReturnUrl(), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $xmlBase64 = base64_encode($xml);
        $xmlBase64Escaped = htmlspecialchars($xmlBase64, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head><meta charset="UTF-8"><title>Transferring cart…</title></head>
            <body>
            <form id="f" method="post" action="{$returnUrl}" target="_top">
                <input type="hidden" name="cXML-base64" value="{$xmlBase64Escaped}">
                <button type="submit">Transfer to procurement system</button>
            </form>
            <script>document.getElementById('f').submit();</script>
            </body>
            </html>
            HTML;

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
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

    private function isTaxIncluded(): bool
    {
        // placeholder — actual tax strategy read in sprint 2
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
