<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cart;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Context\CartNotFoundException;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\CurrentPunchoutSession;

/**
 * Cart context that serves an isolated PunchOut cart.
 *
 * Priority 0 ensures it runs before the default Sylius contexts
 * (session at -555, customer at -777).
 * Only active when a PunchOut session is active; throws CartNotFoundException
 * otherwise so the next context in the chain is used.
 *
 * @see \Sylius\Component\Order\Context\CompositeCartContext
 */
final readonly class PunchoutCartContext implements CartContextInterface
{
    public function __construct(
        private CurrentPunchoutSession $currentPunchoutSession,
    ) {
    }

    public function getCart(): OrderInterface
    {
        $session = $this->currentPunchoutSession->getCurrent();

        if (!$session instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession || !$session->isActive()) {
            throw new CartNotFoundException('No active PunchOut session — use default cart context');
        }

        $cart = $session->getCart();

        if (!$cart instanceof OrderInterface) {
            throw new CartNotFoundException('PunchOut session has no cart assigned yet');
        }

        return $cart;
    }
}
