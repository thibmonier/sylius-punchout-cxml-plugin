<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Buyer;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * Gives a buyer a new shared secret (cXML) or password (OCI), stored as an Argon2id hash only.
 *
 * Rotation without downtime (US-002): the new secret is added next to the current one, so the
 * procurement system can switch over; at most two secrets are active, older ones are revoked. A
 * generated secret is shown once through a dedicated flash message; an imposed one never is.
 */
final readonly class SecretIssuer
{
    public const FLASH = 'tmonier_punchout_secret';

    public const MIN_LENGTH = 16;

    public const MAX_LENGTH = 256;

    public function __construct(
        private CredentialGenerator $generator,
        private CredentialHasher $hasher,
        private RequestStack $requestStack,
    ) {
    }

    /** @param string|null $imposedSecret the secret chosen by the procurement system, or null to generate one */
    public function issue(Buyer $buyer, ?string $imposedSecret = null): void
    {
        if ($imposedSecret !== null && !$this->isAcceptable($imposedSecret)) {
            throw new \InvalidArgumentException(sprintf('A secret has %d to %d characters and is not trivial.', self::MIN_LENGTH, self::MAX_LENGTH));
        }

        $active = $buyer->getActiveCredentials();
        usort($active, static fn (BuyerCredential $a, BuyerCredential $b): int => [$a->getCreatedAt(), $a->getId()] <=> [$b->getCreatedAt(), $b->getId()]);
        foreach (array_slice($active, 0, max(0, count($active) - 1)) as $credential) {
            $credential->revoke();
        }

        $secret = $imposedSecret ?? $this->generator->generate();
        $credential = new BuyerCredential();
        $credential->setLabel((new \DateTimeImmutable())->format('Y-m-d H:i'));
        $credential->setPasswordHash($this->hasher->hash($secret));
        $buyer->addCredential($credential);

        $session = $this->requestStack->getSession();
        if ($imposedSecret === null && $session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->set(self::FLASH, [$secret]);
        }
    }

    /** 16 to 256 characters without the surrounding spaces, and not a single repeated character. */
    private function isAcceptable(string $secret): bool
    {
        $length = mb_strlen(trim($secret));

        return $length >= self::MIN_LENGTH && $length <= self::MAX_LENGTH && count(array_unique(mb_str_split(trim($secret)))) > 1;
    }
}
