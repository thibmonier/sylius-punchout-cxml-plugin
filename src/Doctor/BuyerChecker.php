<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Doctor;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerIdentity;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;

/** US-028: what keeps a buyer from opening a PunchOut session. */
final class BuyerChecker
{
    /** @return list<string> the problems, empty when the buyer can be used */
    public function problems(BuyerInterface $buyer): array
    {
        $problems = [];
        if ($buyer->getReturnDomains() === []) {
            $problems[] = 'no allowed return domain: every return URL is refused';
        }
        if ($buyer->getActiveCredentials() === []) {
            $problems[] = $buyer->getProtocol() === 'oci' ? 'no active secret (the OCI PASSWORD)' : 'no active shared secret';
        }
        if ($buyer->getProtocol() === 'oci' && trim((string) $buyer->getOciUsername()) === '') {
            $problems[] = 'no OCI username';
        }
        if ($buyer->getProtocol() === 'cxml') {
            foreach ([BuyerIdentity::ROLE_FROM => 'From', BuyerIdentity::ROLE_SENDER => 'Sender'] as $role => $name) {
                if ($buyer->getIdentities()->filter(static fn (BuyerIdentity $identity): bool => $identity->getRole() === $role)->isEmpty()) {
                    $problems[] = sprintf('no %s identity', $name);
                }
            }
        }
        $channel = $buyer->getChannel();
        if (!$channel instanceof ChannelInterface || !$channel->isEnabled()) {
            $problems[] = 'no enabled channel';
        }

        return [...$problems, ...$this->shopUserProblems($buyer, $channel)];
    }

    /** @return list<string> */
    private function shopUserProblems(BuyerInterface $buyer, ?ChannelInterface $channel): array
    {
        $user = $buyer->getCustomer()?->getUser();
        if (!$user instanceof ShopUserInterface) {
            return [$buyer->getCustomer() instanceof \Sylius\Component\Core\Model\CustomerInterface ? 'the Sylius customer has no shop account' : 'no Sylius customer'];
        }
        if (!$user->isEnabled()) {
            return ['the shop account of the customer is disabled'];
        }
        if ($channel instanceof ChannelInterface && $channel->isAccountVerificationRequired() && !$user->isVerified()) {
            return ['the shop account of the customer is not verified (required by the channel)'];
        }

        return [];
    }
}
