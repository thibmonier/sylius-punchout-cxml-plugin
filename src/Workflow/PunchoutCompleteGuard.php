<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Workflow;

use Doctrine\DBAL\Connection;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;

/**
 * A PunchOut cart is never completed as a Sylius order: it goes back to the procurement system, where it
 * is approved and ordered (OrderRequest). The checkout routes lead back to the cart already; this guard
 * on the `complete` transition of `sylius_order_checkout` also closes the shop API
 * (`PATCH /api/v2/shop/orders/{token}/complete`).
 *
 * The marker is the link between the cart and a PunchOut session, never the order notes: the orders
 * the plugin builds from an OrderRequest are not session carts and complete normally.
 */
final readonly class PunchoutCompleteGuard
{
    public const BLOCKER_CODE = 'tmonier_punchout.cart_not_completable';

    private const BLOCKER_MESSAGE = 'A PunchOut cart is transferred to the procurement system, it cannot be completed.';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /** Symfony Workflow adapter: `workflow.sylius_order_checkout.guard.complete`. */
    public function __invoke(GuardEvent $event): void
    {
        $order = $event->getSubject();
        if ($order instanceof OrderInterface && !$this->allows($order)) {
            $event->addTransitionBlocker(new TransitionBlocker(self::BLOCKER_MESSAGE, self::BLOCKER_CODE));
        }
    }

    /** Winzou adapter: guard callback of the `complete` transition. */
    public function allows(OrderInterface $order): bool
    {
        return !$this->isPunchoutCart($order);
    }

    public function isPunchoutCart(OrderInterface $order): bool
    {
        $id = $order->getId();
        if ($id === null) {
            return false;
        }

        return false !== $this->connection->fetchOne('SELECT 1 FROM tmonier_punchout_session WHERE order_id = :order', ['order' => $id]);
    }
}
