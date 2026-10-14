<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Log;

interface MessageLogInterface
{
    public function getId(): ?int;

    public function getDirection(): string;

    public function getProtocol(): string;

    public function getMessageType(): string;

    public function getPayloadId(): string;

    public function getStatus(): int;

    public function getBodyHash(): ?string;

    public function getMaskedBody(): ?string;

    public function getCreatedAt(): \DateTimeImmutable;
}
