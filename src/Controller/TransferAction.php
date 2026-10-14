<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Storage\CartStorageInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\MessageOptions;
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
 * POST /punchout/transfer and POST /punchout/cancel (route default `cancel: true`).
 *
 * Sends the PunchOut cart back to the procurement system as a cXML PunchOutOrderMessage posted by
 * the shopper's browser (self-submitting form, `target="_top"` to leave the buyer's iframe), then
 * closes the PunchOut session. Cancelling sends the same message without any item.
 */
final readonly class TransferAction
{
    public const CSRF_TRANSFER = 'tmonier_punchout_transfer';

    public const CSRF_CANCEL = 'tmonier_punchout_cancel';

    public function __construct(
        private CurrentPunchoutSession $currentPunchoutSession,
        private TransferLineExtractor $transferLineExtractor,
        private NetPriceCalculator $netPriceCalculator,
        private OrderMessageWriter $orderMessageWriter,
        private PayloadIdGenerator $payloadIdGenerator,
        private EntityManagerInterface $entityManager,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private ReturnUrlValidator $returnUrlValidator,
        private TwigEnvironment $twig,
        private OrderProcessorInterface $orderProcessor,
        private CartStorageInterface $cartStorage,
        private Security $security,
        private bool $keepTransferredCarts = false,
        private string $supplierDomain = 'NetworkId',
        private string $supplierIdentity = 'supplier',
    ) {
    }

    public function __invoke(Request $request, bool $cancel = false): Response
    {
        $session = $this->currentPunchoutSession->getCurrent();
        if (!$session instanceof PunchoutSession) {
            return $this->plain('No active PunchOut session', Response::HTTP_FORBIDDEN);
        }

        $intent = $cancel ? self::CSRF_CANCEL : self::CSRF_TRANSFER;
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken($intent, $request->request->getString('_csrf_token')))) {
            return $this->plain('Invalid CSRF token', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The return URL was checked at setup time; check it again right before posting the cart to it.
        $returnUrl = $session->getReturnUrl();

        try {
            $this->returnUrlValidator->validate($returnUrl, $session->getBuyer());
        } catch (InvalidReturnUrlException) {
            return $this->plain('Return URL is no longer valid', Response::HTTP_FORBIDDEN);
        }

        // Only a cart is transferred, repriced and deleted: never an order placed in the meantime.
        $cart = $session->getCart();
        if ($cart instanceof OrderInterface && $cart->getState() !== OrderInterface::STATE_CART) {
            $cart = null;
        }
        $lines = [];
        // Inspecting a requisition never sends items back (US-023).
        if (!$cancel && $session->getOperation() !== 'inspect' && $cart instanceof OrderInterface) {
            $this->orderProcessor->process($cart);
            $lines = $this->transferLineExtractor->extract($cart);
        }

        $xml = $this->orderMessageWriter->write(
            $lines,
            $this->netPriceCalculator->computeTotal($lines),
            $cart?->getCurrencyCode() ?? $session->getBuyer()?->getChannel()?->getBaseCurrency()?->getCode() ?? 'EUR',
            $session->getBuyerCookie(),
            $this->payloadIdGenerator->generate(),
            $session->getOperation(),
            $this->supplierDomain,
            $this->supplierIdentity,
            $session->getFromDomain(),
            $session->getFromIdentity(),
            $this->messageOptions($session, $cancel ? null : $cart),
        );

        $this->close($session, $cart, $cancel ? PunchoutSession::STATUS_CANCELLED : PunchoutSession::STATUS_TRANSFERRED);

        return $this->postToReturnUrl($returnUrl, base64_encode($xml));
    }

    /** Language of the descriptions and, when the buyer wants them (US-019), tax and shipping without tax. */
    private function messageOptions(PunchoutSession $session, ?OrderInterface $cart): MessageOptions
    {
        $language = str_replace('_', '-', $cart?->getLocaleCode() ?? $session->getBuyer()?->getChannel()?->getDefaultLocale()?->getCode() ?? 'en');
        $buyer = $session->getBuyer();
        if (!$cart instanceof OrderInterface || !$buyer instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface) {
            return new MessageOptions($language);
        }
        $shipping = $cart->getAdjustmentsTotal(AdjustmentInterface::SHIPPING_ADJUSTMENT)
            + $cart->getAdjustmentsTotal(AdjustmentInterface::ORDER_SHIPPING_PROMOTION_ADJUSTMENT);

        return new MessageOptions(
            $language,
            $buyer->isIncludeTax() ? $this->decimal($cart->getTaxTotal()) : null,
            $buyer->getIncludeShipping() === true ? $this->decimal($shipping) : null,
        );
    }

    private function decimal(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    /**
     * Ends the PunchOut session: the shopper stays logged in and their regular cart becomes the current
     * cart again. The PunchOut cart is deleted unless `keep_transferred_carts` is enabled.
     */
    private function close(PunchoutSession $session, ?OrderInterface $cart, string $status): void
    {
        $session->setStatus($status);
        $this->currentPunchoutSession->clear();

        if ($cart instanceof OrderInterface) {
            // Sylius stores the current cart id in the HTTP session on every response: forget it.
            if ($cart->getChannel() instanceof ChannelInterface) {
                $this->cartStorage->removeForChannel($cart->getChannel());
            }
            if (!$this->keepTransferredCarts) {
                $session->setCart(null);
                $this->entityManager->remove($cart);
            }
        }

        $this->entityManager->flush();

        // The shopper used the buyer's shared customer account for the PunchOut session only.
        $this->security->logout(false);
    }

    private function postToReturnUrl(string $returnUrl, string $xmlBase64): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $parts = parse_url($returnUrl);
        $origin = sprintf(
            '%s://%s%s',
            is_array($parts) ? ($parts['scheme'] ?? 'https') : 'https',
            is_array($parts) ? ($parts['host'] ?? '') : '',
            is_array($parts) && isset($parts['port']) ? ':' . $parts['port'] : '',
        );

        $response = new Response($this->twig->render('@TmonierSyliusPunchoutCxmlPlugin/punchout/transfer.html.twig', [
            'returnUrl' => $returnUrl,
            'xmlBase64' => $xmlBase64,
            'nonce' => $nonce,
        ]), Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Content-Security-Policy', sprintf(
            "default-src 'none'; script-src 'nonce-%s'; form-action %s; base-uri 'none'",
            $nonce,
            $origin,
        ));

        return $response;
    }

    private function plain(string $message, int $status): Response
    {
        return new Response($message, $status, ['Cache-Control' => 'no-store']);
    }
}
