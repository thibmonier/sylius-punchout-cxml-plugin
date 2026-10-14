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
    ) {
    }

    public function getCurrent(): ?PunchoutSession
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof \Symfony\Component\HttpFoundation\Request) {
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

        // The shop firewall keeps the session on logout: the PunchOut session only belongs to the
        // customer of its buyer, never to another customer logged in later in the same browser.
        $user = $this->tokenStorage->getToken()?->getUser();
        $customer = $punchoutSession->getBuyer()?->getCustomer();
        if (!$user instanceof ShopUserInterface || $customer === null || $user->getCustomer()?->getId() !== $customer->getId()) {
            return null;
        }

        return $punchoutSession;
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
