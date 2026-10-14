<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Unit\Buyer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\CredentialHasher;

final class CredentialHasherTest extends TestCase
{
    private CredentialHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new CredentialHasher();
    }

    #[Test]
    public function it_hashes_a_secret(): void
    {
        // Secret is generated at runtime — NEVER hard-coded
        $secret = bin2hex(random_bytes(16));

        $hash = $this->hasher->hash($secret);

        self::assertNotSame($secret, $hash);
        self::assertStringStartsWith('$argon2id$', $hash);
    }

    #[Test]
    public function it_verifies_a_correct_secret(): void
    {
        $secret = bin2hex(random_bytes(16));
        $hash = $this->hasher->hash($secret);

        self::assertTrue($this->hasher->verify($secret, $hash));
    }

    #[Test]
    public function it_rejects_a_wrong_secret(): void
    {
        $secret = bin2hex(random_bytes(16));
        $wrongSecret = bin2hex(random_bytes(16));
        $hash = $this->hasher->hash($secret);

        self::assertFalse($this->hasher->verify($wrongSecret, $hash));
    }

    #[Test]
    public function it_generates_different_hashes_for_same_input(): void
    {
        $secret = bin2hex(random_bytes(16));

        $hash1 = $this->hasher->hash($secret);
        $hash2 = $this->hasher->hash($secret);

        // Argon2id generates a random salt each time
        self::assertNotSame($hash1, $hash2);
        self::assertTrue($this->hasher->verify($secret, $hash1));
        self::assertTrue($this->hasher->verify($secret, $hash2));
    }
}
