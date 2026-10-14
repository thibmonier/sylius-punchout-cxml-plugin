<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Log;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSessionInterface;

/**
 * Journal of the PunchOut exchanges (US-024): every request received and message sent, with a
 * SHA-256 of the body and, only when `log_bodies` is enabled, the masked body. Failed or refused
 * requests are also security events (PSR logger, warning).
 */
final readonly class MessageLogger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BodyMasker $masker,
        private LoggerInterface $logger,
        private bool $logBodies = false,
    ) {
    }

    public function log(
        string $direction,
        string $messageType,
        string $payloadId,
        int $status,
        string $body,
        ?BuyerInterface $buyer = null,
        ?PunchoutSessionInterface $session = null,
    ): void {
        $log = new MessageLog();
        $log->setDirection($direction);
        $log->setMessageType(mb_substr($messageType, 0, 100));
        $log->setPayloadId(mb_substr($payloadId, 0, 255));
        $log->setStatus($status);
        $log->setBuyer($buyer);
        $log->setSession($session);
        $log->setBodyHash(hash('sha256', $body));
        $log->setMaskedBody($this->logBodies ? $this->masker->mask($body) : null);

        if ($this->entityManager->isOpen()) {
            $this->entityManager->persist($log);
            $this->entityManager->flush();
        }

        if ($status >= 400) {
            $this->logger->warning('PunchOut request refused', ['type' => $messageType, 'status' => $status, 'buyer' => $buyer?->getCode()]);
        }
    }
}
