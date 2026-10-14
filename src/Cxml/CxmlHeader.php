<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cxml;

/** Envelope of a cXML request: identities, shared secret, payloadID and timestamp. */
final readonly class CxmlHeader
{
    public function __construct(
        public string $payloadId,
        public string $timestamp,
        public string $fromDomain,
        public string $fromIdentity,
        public string $senderDomain,
        public string $senderIdentity,
        public string $sharedSecret,
    ) {
    }
}
