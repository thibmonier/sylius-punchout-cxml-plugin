<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\CurrentPunchoutSession;

/**
 * `is_granted('PUNCHOUT_SESSION')`: the shopper is in a PunchOut session. Used by the twig hooks
 * conditions to replace the Sylius checkout with the transfer (US-014).
 *
 * `?Vote $vote` is required by Symfony 8 and ignored by Symfony 6.4 / 7 (type not loaded).
 *
 * @extends Voter<string, mixed>
 */
final class PunchoutSessionVoter extends Voter
{
    public const ATTRIBUTE = 'PUNCHOUT_SESSION';

    public function __construct(private readonly CurrentPunchoutSession $currentPunchoutSession)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $this->currentPunchoutSession->getCurrent() instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;
    }
}
