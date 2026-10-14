<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\EventListener;

use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\SecretIssuer;

/** A buyer created in the admin gets its first secret, shown once on the next page. */
final readonly class BuyerSecretListener
{
    public function __construct(private SecretIssuer $secretIssuer)
    {
    }

    public function onPreCreate(ResourceControllerEvent $event): void
    {
        $buyer = $event->getSubject();
        if ($buyer instanceof Buyer && $buyer->getActiveCredentials() === []) {
            $this->secretIssuer->issue($buyer);
        }
    }
}
