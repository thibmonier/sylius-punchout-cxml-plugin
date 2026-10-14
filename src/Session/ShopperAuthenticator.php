<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Session;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates the customer linked to the PunchOut buyer into the shop.
 *
 * Performs manual pre-auth checks before calling Security::login():
 * - customer exists and has an associated shop user
 * - shop user is enabled
 * - shop user's channel is active
 *
 * This is necessary because Sylius 2.3 uses a permissive pre-auth strategy
 * (UserChecker is relaxed; we must check manually).
 */
final readonly class ShopperAuthenticator
{
    public function __construct(
        private CurrentPunchoutSession $currentSession,
        private \Symfony\Bundle\SecurityBundle\Security $security,
    ) {
    }

    /**
     * Authenticates the buyer's customer into the shop session.
     *
     * @throws \RuntimeException if the customer or shop user is invalid
     */
    public function authenticate(PunchoutSession $session, Request $request): void
    {
        $buyer = $session->getBuyer();
        if (!$buyer instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface) {
            throw new \RuntimeException('PunchOut session has no buyer');
        }

        $customer = $buyer->getCustomer();
        if (!$customer instanceof CustomerInterface) {
            throw new \RuntimeException('Buyer has no linked customer');
        }

        $shopUser = $customer->getUser();
        if (!$shopUser instanceof ShopUserInterface) {
            throw new \RuntimeException('Customer has no shop user account');
        }

        if (!$shopUser->isEnabled()) {
            throw new \RuntimeException('Shop user account is disabled');
        }

        // Log the user into the shop firewall
        $this->security->login($shopUser, 'form_login', 'shop');

        // Regenerate the session ID to prevent session fixation
        $request->getSession()->migrate(true);

        // Store the PunchOut session id in the Symfony session
        $this->currentSession->set($session);
    }
}
