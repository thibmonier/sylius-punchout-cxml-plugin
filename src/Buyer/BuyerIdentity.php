<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Buyer;

/**
 * Represents a cXML From or Sender identity (domain + identity string).
 * The combination (role, domain, identity) must be globally unique.
 */
class BuyerIdentity
{
    private ?int $id = null;

    private ?Buyer $buyer = null;

    public const ROLE_FROM = 'From';

    public const ROLE_SENDER = 'Sender';

    private string $role = self::ROLE_FROM;

    private string $domain = '';

    private string $identity = '';

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

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): void
    {
        $this->role = $role;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): void
    {
        $this->domain = $domain;
    }

    public function getIdentity(): string
    {
        return $this->identity;
    }

    public function setIdentity(string $identity): void
    {
        $this->identity = $identity;
    }
}
