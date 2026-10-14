<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Buyer;

/**
 * Verifies a shared secret against the buyer's active credentials.
 *
 * When the buyer is unknown a dummy verification is performed to ensure
 * constant-time response and prevent user-enumeration via timing.
 */
final readonly class CredentialVerifier
{
    private CredentialHasher $hasher;

    /** Pre-computed dummy hash to verify against when the buyer is unknown. */
    private string $dummyHash;

    public function __construct(?CredentialHasher $hasher = null)
    {
        $this->hasher = $hasher ?? new CredentialHasher();
        // Computed once at construction; never store a real secret here.
        $this->dummyHash = $this->hasher->hash(bin2hex(random_bytes(16)));
    }

    /**
     * Returns true only when $secret matches at least one active credential of $buyer.
     * When $buyer is null or has no active credentials, performs a dummy check via the
     * hasher to maintain constant-time behaviour and prevent timing-based enumeration.
     */
    public function verify(string $secret, ?BuyerInterface $buyer): bool
    {
        if (!$buyer instanceof \Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface) {
            // Constant-time dummy verification — always returns false.
            $this->hasher->verify($secret, $this->dummyHash);

            return false;
        }

        $credentials = $buyer->getActiveCredentials();

        if ($credentials === []) {
            // No active credentials — still perform dummy verify to prevent timing attacks.
            $this->hasher->verify($secret, $this->dummyHash);

            return false;
        }

        foreach ($credentials as $credential) {
            if ($this->hasher->verify($secret, $credential->getPasswordHash())) {
                return true;
            }
        }

        return false;
    }
}
