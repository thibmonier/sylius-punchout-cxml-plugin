<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Session;

use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;

/**
 * Lifecycle: pending → active → transferred | cancelled
 *            pending → expired (token not consumed in TTL)
 *            active  → expired (purge)
 */
class PunchoutSession implements PunchoutSessionInterface
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_TRANSFERRED = 'transferred';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    private ?int $id = null;

    private ?BuyerInterface $buyer = null;

    private string $operation = 'create';

    private string $buyerCookie = '';

    private string $returnUrl = '';

    private ?string $startTokenHash = null;

    private ?\DateTimeImmutable $startTokenExpiry = null;

    private string $status = self::STATUS_PENDING;

    /** @var array<string, string> */
    private array $extrinsics = [];

    /** @var list<array<string, mixed>> */
    private array $itemsOut = [];

    private ?OrderInterface $cart = null;

    /** R4: buyer's From domain (from cXML Header/From/Credential) */
    private string $fromDomain = '';

    /** R4: buyer's From identity (from cXML Header/From/Credential) */
    private string $fromIdentity = '';

    private readonly \DateTimeImmutable $createdAt;

    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBuyer(): ?BuyerInterface
    {
        return $this->buyer;
    }

    public function setBuyer(?BuyerInterface $buyer): void
    {
        $this->buyer = $buyer;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function setOperation(string $operation): void
    {
        $this->operation = $operation;
    }

    public function getBuyerCookie(): string
    {
        return $this->buyerCookie;
    }

    public function setBuyerCookie(string $buyerCookie): void
    {
        $this->buyerCookie = $buyerCookie;
    }

    public function getReturnUrl(): string
    {
        return $this->returnUrl;
    }

    public function setReturnUrl(string $returnUrl): void
    {
        $this->returnUrl = $returnUrl;
    }

    public function getStartTokenHash(): ?string
    {
        return $this->startTokenHash;
    }

    public function setStartTokenHash(?string $hash): void
    {
        $this->startTokenHash = $hash;
    }

    public function getStartTokenExpiry(): ?\DateTimeImmutable
    {
        return $this->startTokenExpiry;
    }

    public function setStartTokenExpiry(?\DateTimeImmutable $expiry): void
    {
        $this->startTokenExpiry = $expiry;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getExtrinsics(): array
    {
        return $this->extrinsics;
    }

    public function setExtrinsics(array $extrinsics): void
    {
        $this->extrinsics = $extrinsics;
    }

    public function getItemsOut(): array
    {
        return $this->itemsOut;
    }

    public function setItemsOut(array $itemsOut): void
    {
        $this->itemsOut = $itemsOut;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCart(): ?OrderInterface
    {
        return $this->cart;
    }

    public function setCart(?OrderInterface $cart): void
    {
        $this->cart = $cart;
    }

    public function getFromDomain(): string
    {
        return $this->fromDomain;
    }

    public function setFromDomain(string $fromDomain): void
    {
        $this->fromDomain = $fromDomain;
    }

    public function getFromIdentity(): string
    {
        return $this->fromIdentity;
    }

    public function setFromIdentity(string $fromIdentity): void
    {
        $this->fromIdentity = $fromIdentity;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
