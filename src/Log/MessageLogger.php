<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Log;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSessionInterface;

/**
 * Journal of the PunchOut exchanges (US-024): every request received and message sent, with the
 * SHA-256 of the body (of its first `max_request_bytes` at most) and, only when `log_bodies` is
 * enabled and the sender was authenticated, the masked body. Written with DBAL: it never flushes the
 * pending changes of the caller. Refused requests are also security events (PSR logger, warning).
 */
final readonly class MessageLogger
{
    private const TABLE = 'tmonier_punchout_message_log';

    public function __construct(
        private Connection $connection,
        private BodyMasker $masker,
        private LoggerInterface $logger,
        private bool $logBodies = false,
        private int $maxBodyBytes = 262144,
    ) {
    }

    /**
     * @param bool $authenticated whether the body was sent by an authenticated buyer (or by the shop)
     * @param array<string, string|null> $context security context of a refused request (address, identity)
     */
    public function log(
        string $direction,
        string $messageType,
        string $payloadId,
        int $status,
        string $body,
        ?BuyerInterface $buyer = null,
        ?PunchoutSessionInterface $session = null,
        bool $authenticated = true,
        array $context = [],
        string $protocol = MessageLog::PROTOCOL_CXML,
    ): void {
        $this->connection->insert(self::TABLE, [
            'buyer_id' => $buyer?->getId(),
            'session_id' => $session?->getId(),
            'direction' => $direction,
            'protocol' => $protocol,
            'message_type' => mb_substr($messageType, 0, 100),
            'payload_id' => mb_substr($payloadId, 0, 255),
            'status' => $status,
            'body_hash' => hash('sha256', substr($body, 0, $this->maxBodyBytes)),
            'masked_body' => $this->logBodies && $authenticated && strlen($body) <= $this->maxBodyBytes ? $this->masked($body, $protocol) : null,
            'created_at' => new \DateTimeImmutable(),
        ], ['created_at' => Types::DATETIME_IMMUTABLE]);

        if ($status >= 400) {
            $this->logger->warning('PunchOut request refused', ['type' => $messageType, 'status' => $status, 'buyer' => $buyer?->getCode()] + $context);
        }
    }

    /** OCI bodies are form parameters, already without the password: only e-mail addresses are masked. */
    private function masked(string $body, string $protocol): string
    {
        return $protocol === MessageLog::PROTOCOL_OCI ? $this->masker->maskText($body) : $this->masker->mask($body);
    }
}
