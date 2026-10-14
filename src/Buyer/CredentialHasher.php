<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Buyer;

/**
 * Hashes buyer secrets with Argon2id (ADR-004).
 *
 * Parameters: memory 128 MiB, time cost 3, threads 1.
 */
final class CredentialHasher
{
    private const OPTIONS = [
        'memory_cost' => 131072, // 128 MiB
        'time_cost' => 3,
        'threads' => 1,
    ];

    public function hash(string $secret): string
    {
        return password_hash($secret, \PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public function verify(string $secret, string $hash): bool
    {
        return password_verify($secret, $hash);
    }
}
