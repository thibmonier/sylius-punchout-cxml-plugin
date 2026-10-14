<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Twig;

use Tmonier\SyliusPunchoutCxmlPlugin\Session\CurrentPunchoutSession;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PunchoutExtension extends AbstractExtension
{
    public function __construct(private readonly CurrentPunchoutSession $currentPunchoutSession)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tmonier_punchout_session', $this->currentSession(...))];
    }

    /** The PunchOut session of the logged-in shopper, or null outside a PunchOut session. */
    public function currentSession(): ?PunchoutSession
    {
        return $this->currentPunchoutSession->getCurrent();
    }
}
