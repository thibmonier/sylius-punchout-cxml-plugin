<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Session;

use Sylius\Resource\Model\ResourceInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;

interface PunchoutSessionInterface extends ResourceInterface
{
    public function getId(): ?int;

    public function getBuyer(): ?BuyerInterface;

    public function setBuyer(?BuyerInterface $buyer): void;

    public function getOperation(): string;

    public function setOperation(string $operation): void;

    public function getBuyerCookie(): string;

    public function setBuyerCookie(string $buyerCookie): void;

    public function getReturnUrl(): string;

    public function setReturnUrl(string $returnUrl): void;

    public function getStartTokenHash(): ?string;

    public function setStartTokenHash(?string $hash): void;

    public function getStartTokenExpiry(): ?\DateTimeImmutable;

    public function setStartTokenExpiry(?\DateTimeImmutable $expiry): void;

    public function getStatus(): string;

    public function setStatus(string $status): void;

    /** @return array<string, string> */
    public function getExtrinsics(): array;

    /** @param array<string, string> $extrinsics */
    public function setExtrinsics(array $extrinsics): void;

    /** @return list<array<string, mixed>> */
    public function getItemsOut(): array;

    /** @param list<array<string, mixed>> $itemsOut */
    public function setItemsOut(array $itemsOut): void;

    public function getCreatedAt(): \DateTimeImmutable;

    public function getUpdatedAt(): \DateTimeImmutable;

    public function isPending(): bool;

    public function isActive(): bool;
}
