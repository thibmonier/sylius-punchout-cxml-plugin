<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Session;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Retrieves the active PunchOut session for the current request from the Symfony session.
 *
 * The session key '_tmonier_punchout.session' stores the PunchoutSession id.
 */
final readonly class CurrentPunchoutSession
{
    public const SESSION_KEY = '_tmonier_punchout.session';

    public function __construct(
        private RequestStack $requestStack,
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokenStorage,
        private int $sessionLifetime = 28800,
    ) {
    }

    public function getCurrent(): ?PunchoutSession
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof \Symfony\Component\HttpFoundation\Request) {
            return null;
        }

        // Never start a session (anonymous visitors, cacheable pages): only an existing one may hold it.
        if (!$request->hasSession() || (!$request->hasPreviousSession() && !$request->getSession()->isStarted())) {
            return null;
        }
        $session = $request->getSession();
        $id = $session->get(self::SESSION_KEY);
        if ($id === null) {
            return null;
        }

        /** @var PunchoutSession|null $punchoutSession */
        $punchoutSession = $this->entityManager->find(PunchoutSession::class, $id);
        if ($punchoutSession === null || !$punchoutSession->isActive()) {
            return null;
        }

        // An abandoned PunchOut session does not stay open forever (US-017).
        if ($punchoutSession->getCreatedAt()->getTimestamp() + $this->sessionLifetime < time()) {
            $punchoutSession->setStatus(PunchoutSession::STATUS_EXPIRED);
            $this->entityManager->flush();

            return null;
        }

        // The shop firewall keeps the session on logout: the PunchOut session only belongs to the
        // customer of its buyer, never to another customer logged in later in the same browser.
        $user = $this->tokenStorage->getToken()?->getUser();
        $buyer = $punchoutSession->getBuyer();
        if ($buyer === null || !$buyer->isEnabled()) {
            return null;
        }
        $customer = $buyer->getCustomer();
        if (!$user instanceof ShopUserInterface || $customer === null || $user->getCustomer()?->getId() !== $customer->getId()) {
            return null;
        }

        return $punchoutSession;
    }

    /**
     * The PunchOut session referenced by the HTTP session for the logged-in customer of its buyer, but
     * no longer open (transferred, cancelled, expired): the shopper must not stay logged in.
     */
    public function findEnded(): ?PunchoutSession
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof \Symfony\Component\HttpFoundation\Request || !$request->hasPreviousSession()) {
            return null;
        }
        $id = $request->getSession()->get(self::SESSION_KEY);
        if ($id === null || $this->getCurrent() instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession) {
            return null;
        }
        $punchoutSession = $this->entityManager->find(PunchoutSession::class, $id);
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$punchoutSession instanceof PunchoutSession || !$user instanceof ShopUserInterface) {
            return null;
        }

        return $user->getCustomer()?->getId() === $punchoutSession->getBuyer()?->getCustomer()?->getId() ? $punchoutSession : null;
    }

    public function set(PunchoutSession $session): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $request?->getSession()->set(self::SESSION_KEY, $session->getId());
    }

    public function clear(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $request?->getSession()->remove(self::SESSION_KEY);
    }
}
