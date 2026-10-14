<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Buyer;

/**
 * A hashed buyer credential (Argon2id).
 * At most 2 active credentials per buyer to allow secret rotation.
 */
class BuyerCredential
{
    private ?int $id = null;

    private ?Buyer $buyer = null;

    private string $label = '';

    /** Argon2id hash of the shared secret. */
    private string $passwordHash = '';

    private readonly \DateTimeImmutable $createdAt;

    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBuyer(): ?Buyer
    {
        return $this->buyer;
    }

    public function setBuyer(?Buyer $buyer): void
    {
        $this->buyer = $buyer;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return !$this->revokedAt instanceof \DateTimeImmutable;
    }
}
