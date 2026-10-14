<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Session;

use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;

/**
 * Creates a new PunchoutSession from a parsed setup request.
 */
final class SessionFactory
{
    /**
     * @param array<string, string> $extrinsics
     * @param list<array<string, mixed>> $itemsOut
     */
    public function create(
        BuyerInterface $buyer,
        string $operation,
        string $buyerCookie,
        string $returnUrl,
        array $extrinsics = [],
        array $itemsOut = [],
    ): PunchoutSession {
        $session = new PunchoutSession();
        $session->setBuyer($buyer);
        $session->setOperation($operation);
        $session->setBuyerCookie($buyerCookie);
        $session->setReturnUrl($returnUrl);
        $session->setExtrinsics($extrinsics);
        $session->setItemsOut($itemsOut);

        return $session;
    }
}
