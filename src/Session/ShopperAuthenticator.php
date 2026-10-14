<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Session;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates the customer linked to the PunchOut buyer into the shop.
 *
 * Performs manual pre-auth checks before calling Security::login():
 * - customer exists and has an associated shop user
 * - shop user is enabled
 * - channel is enabled (R7)
 * - shop user has verified their email when the channel requires it (R7)
 *
 * R7 fix: session.migrate(true) is called BEFORE security.login() to
 * prevent session fixation (the new session ID is established first,
 * then the user is associated with it).
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

        if (!$buyer->isEnabled()) {
            throw new \RuntimeException('PunchOut buyer is disabled');
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

        // R7: the buyer's channel exists and is active (its cart is created in it)
        $channel = $buyer->getChannel();
        if (!$channel instanceof ChannelInterface || !$channel->isEnabled()) {
            throw new \RuntimeException('PunchOut channel is disabled');
        }

        // R7: verify the shopper's email when the channel requires it
        if (
            $channel->isAccountVerificationRequired() &&
            !$shopUser->isVerified()
        ) {
            throw new \RuntimeException('Shop user account is not verified');
        }

        // R7 fix: regenerate the session ID BEFORE login to prevent session fixation.
        // Calling migrate(true) first destroys the old (potentially logged-in) session
        // and issues a new session ID. The user is then bound to this fresh session.
        $request->getSession()->migrate(true);

        // Log the user into the shop firewall
        $this->security->login($shopUser, 'form_login', 'shop');

        // Store the PunchOut session id in the Symfony session
        $this->currentSession->set($session);
    }
}
