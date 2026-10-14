<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\SecretIssuer;

/** POST /admin/punchout-buyers/{id}/regenerate-secret: revokes the secret and shows a new one once. */
final readonly class RegenerateSecretAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SecretIssuer $secretIssuer,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function __invoke(Request $request, int $id): RedirectResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('tmonier_punchout_regenerate_secret' . $id, $request->request->getString('_csrf_token')))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $buyer = $this->entityManager->find(Buyer::class, $id);
        if (!$buyer instanceof Buyer) {
            throw new NotFoundHttpException();
        }

        $this->secretIssuer->issue($buyer);
        $this->entityManager->flush();
        // Security event: who revoked the secret of which buyer (never the secret itself).
        $this->logger->notice('PunchOut buyer secret regenerated', [
            'buyer' => $buyer->getCode(),
            'admin' => $this->tokenStorage->getToken()?->getUserIdentifier(),
        ]);

        return new RedirectResponse($this->urlGenerator->generate('tmonier_punchout_admin_buyer_update', ['id' => $id]));
    }
}
