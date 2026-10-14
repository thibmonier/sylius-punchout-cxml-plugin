<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Log;

use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSessionInterface;

/**
 * Audit log entry for each cXML/OCI exchange.
 * Bodies are only stored when log_bodies: true (default: false in production).
 * Bodies are always masked by CxmlMasker before storage.
 */
class MessageLog implements MessageLogInterface
{
    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    public const PROTOCOL_CXML = 'cxml';

    public const PROTOCOL_OCI = 'oci';

    private ?int $id = null;

    private string $direction = self::DIRECTION_IN;

    private string $protocol = self::PROTOCOL_CXML;

    private string $messageType = '';

    private string $payloadId = '';

    private int $status = 0;

    private ?BuyerInterface $buyer = null;

    private ?PunchoutSessionInterface $session = null;

    private ?string $bodyHash = null;

    private ?string $maskedBody = null;

    private readonly \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDirection(): string
    {
        return $this->direction;
    }

    public function setDirection(string $direction): void
    {
        $this->direction = $direction;
    }

    public function getProtocol(): string
    {
        return $this->protocol;
    }

    public function setProtocol(string $protocol): void
    {
        $this->protocol = $protocol;
    }

    public function getMessageType(): string
    {
        return $this->messageType;
    }

    public function setMessageType(string $messageType): void
    {
        $this->messageType = $messageType;
    }

    public function getPayloadId(): string
    {
        return $this->payloadId;
    }

    public function setPayloadId(string $payloadId): void
    {
        $this->payloadId = $payloadId;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    public function getBuyer(): ?BuyerInterface
    {
        return $this->buyer;
    }

    public function setBuyer(?BuyerInterface $buyer): void
    {
        $this->buyer = $buyer;
    }

    public function getSession(): ?PunchoutSessionInterface
    {
        return $this->session;
    }

    public function setSession(?PunchoutSessionInterface $session): void
    {
        $this->session = $session;
    }

    public function getBodyHash(): ?string
    {
        return $this->bodyHash;
    }

    public function setBodyHash(?string $bodyHash): void
    {
        $this->bodyHash = $bodyHash;
    }

    public function getMaskedBody(): ?string
    {
        return $this->maskedBody;
    }

    public function setMaskedBody(?string $maskedBody): void
    {
        $this->maskedBody = $maskedBody;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
