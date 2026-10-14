<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Security;

use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;

/** A payloadID already accepted from a buyer (anti-replay), kept until the timestamp window is over. */
class SeenPayload
{
    private ?int $id = null;

    public function __construct(
        private readonly Buyer $buyer,
        private readonly string $payloadHash,
        private readonly \DateTimeImmutable $expiresAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBuyer(): Buyer
    {
        return $this->buyer;
    }

    public function getPayloadHash(): string
    {
        return $this->payloadHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
