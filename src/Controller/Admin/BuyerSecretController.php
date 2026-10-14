<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\SecretIssuer;

/**
 * Secrets of a buyer in the admin (US-002): generate a new one (shown once), enter the one imposed by
 * the procurement system, revoke one. POST only, CSRF token per buyer, each change logged (never the
 * secret itself).
 */
final readonly class BuyerSecretController
{
    public const CSRF_PREFIX = 'tmonier_punchout_secret';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SecretIssuer $secretIssuer,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function generate(Request $request, int $id): RedirectResponse
    {
        $buyer = $this->buyer($request, $id);
        $this->secretIssuer->issue($buyer);
        $this->entityManager->flush();
        $this->log('PunchOut buyer secret generated', $buyer);

        return $this->backTo($buyer);
    }

    public function set(Request $request, int $id): RedirectResponse
    {
        $buyer = $this->buyer($request, $id);

        try {
            $this->secretIssuer->issue($buyer, $request->request->getString('secret'));
        } catch (\InvalidArgumentException) {
            $this->flash($request, 'error', 'tmonier_punchout.secret.too_short');

            return $this->backTo($buyer);
        }
        $this->entityManager->flush();
        $this->flash($request, 'success', 'tmonier_punchout.secret.set');
        $this->log('PunchOut buyer secret set', $buyer);

        return $this->backTo($buyer);
    }

    public function revoke(Request $request, int $id, int $credentialId): RedirectResponse
    {
        $buyer = $this->buyer($request, $id);
        foreach ($buyer->getActiveCredentials() as $credential) {
            if ($credential->getId() === $credentialId) {
                $credential->revoke();
                $this->entityManager->flush();
                $this->log('PunchOut buyer secret revoked', $buyer);
            }
        }

        return $this->backTo($buyer);
    }

    private function buyer(Request $request, int $id): Buyer
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_PREFIX . $id, $request->request->getString('_csrf_token')))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $buyer = $this->entityManager->find(Buyer::class, $id);
        if (!$buyer instanceof Buyer) {
            throw new NotFoundHttpException();
        }

        return $buyer;
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    private function log(string $message, Buyer $buyer): void
    {
        // Security event: who changed the secrets of which buyer (never the secret itself).
        $this->logger->notice($message, ['buyer' => $buyer->getCode(), 'admin' => $this->tokenStorage->getToken()?->getUserIdentifier()]);
    }

    private function backTo(Buyer $buyer): RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate('tmonier_punchout_admin_buyer_update', ['id' => $buyer->getId()]));
    }
}
