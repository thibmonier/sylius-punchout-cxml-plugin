<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cxml;

/**
 * Value object extracted from a PunchOutSetupRequest document.
 */
final readonly class SetupRequestData
{
    /**
     * @param 'create'|'edit'|'inspect' $operation
     * @param array<string, string> $extrinsics
     * @param list<array<string, mixed>> $itemsOut
     */
    public function __construct(
        public string $operation,
        public string $payloadId,
        public string $timestamp,
        public string $fromDomain,
        public string $fromIdentity,
        public string $senderDomain,
        public string $senderIdentity,
        public string $sharedSecret,
        public string $buyerCookie,
        public string $browserFormPostUrl,
        public array $extrinsics = [],
        public array $itemsOut = [],
    ) {
    }
}
