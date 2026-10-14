<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Buyer;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * Gives a buyer a new shared secret (cXML) or password (OCI): only its Argon2id hash is stored, the
 * plain value is shown once to the administrator through a dedicated flash message.
 */
final readonly class SecretIssuer
{
    public const FLASH = 'tmonier_punchout_secret';

    public function __construct(
        private CredentialGenerator $generator,
        private CredentialHasher $hasher,
        private RequestStack $requestStack,
    ) {
    }

    /** Revokes the active secrets and issues a new one (rotation with two active secrets is US-002). */
    public function issue(Buyer $buyer): void
    {
        foreach ($buyer->getActiveCredentials() as $credential) {
            $credential->revoke();
        }

        $secret = $this->generator->generate();
        $credential = new BuyerCredential();
        $credential->setLabel((new \DateTimeImmutable())->format('Y-m-d H:i'));
        $credential->setPasswordHash($this->hasher->hash($secret));
        $buyer->addCredential($credential);

        $session = $this->requestStack->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->set(self::FLASH, [$secret]);
        }
    }
}
