<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Order;

use Sylius\Component\Core\Model\OrderInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;

/** A purchase order received by OrderRequest (US-030): one per buyer and orderID, with its Sylius order. */
class ReceivedPurchaseOrder
{
    private ?int $id = null;

    public function __construct(
        private readonly Buyer $buyer,
        private readonly string $purchaseOrder,
        private readonly string $contentHash,
        private readonly string $payloadId,
        private readonly \DateTimeImmutable $createdAt,
        private readonly ?OrderInterface $order = null,
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

    public function getPurchaseOrder(): string
    {
        return $this->purchaseOrder;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function getPayloadId(): string
    {
        return $this->payloadId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }
}
