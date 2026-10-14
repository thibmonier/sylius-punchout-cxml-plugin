<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\EventListener;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Storage\CartStorageInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\CurrentPunchoutSession;

/**
 * Sylius stores the id of the current cart in the HTTP session on every response
 * (SessionCartSubscriber, priority 0). During a PunchOut session that is the PunchOut cart: forget it,
 * so that it can never become the shopper's regular cart once the PunchOut session is over, however it
 * ends (transfer, buyer disabled, expiry).
 */
final readonly class PunchoutCartStorageListener
{
    public function __construct(
        private CurrentPunchoutSession $currentPunchoutSession,
        private CartStorageInterface $cartStorage,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasSession() || !$request->getSession()->isStarted()) {
            return;
        }

        $cart = $this->currentPunchoutSession->getCurrent()?->getCart();
        $channel = $cart?->getChannel();
        if (!$cart instanceof OrderInterface || !$channel instanceof ChannelInterface || !$this->cartStorage->hasForChannel($channel)) {
            return;
        }

        if ($this->cartStorage->getForChannel($channel)?->getId() === $cart->getId()) {
            $this->cartStorage->removeForChannel($channel);
        }
    }
}
