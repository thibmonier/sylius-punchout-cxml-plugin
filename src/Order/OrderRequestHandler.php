<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Order;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\CxmlStatus;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\OrderRequestData;

/**
 * Receives an OrderRequest once (US-030, ADR-0008): opt-in per buyer, one order per buyer and orderID
 * — the purchase order is claimed in the same transaction as the order, so a concurrent repost finds
 * it and a refused one can be sent again once fixed.
 */
final readonly class OrderRequestHandler
{
    private const TABLE = 'tmonier_punchout_order_request';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private OrderBuilder $orderBuilder,
    ) {
    }

    /**
     * The number of the order already created for this purchase order (a repost, even of the very same
     * document: answered before the replay guard), null when it is new.
     */
    public function knownOrderNumber(Buyer $buyer, OrderRequestData $request): ?string
    {
        $claim = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT o.number, r.order_id, r.content_hash FROM ' . self::TABLE . ' r LEFT JOIN sylius_order o ON o.id = r.order_id WHERE r.buyer_id = :buyer AND r.purchase_order = :po',
            ['buyer' => $buyer->getId(), 'po' => $request->orderId],
        );
        if ($claim === false) {
            return null;
        }
        if ($claim['content_hash'] !== $request->contentHash()) {
            throw new OrderRequestRejected('orderID already used with a different content');
        }
        if (!is_string($claim['number'])) {
            throw new OrderRequestRejected('Purchase order already received, its order no longer exists or is being created', CxmlStatus::OPERATION_FAILED);
        }

        return $claim['number'];
    }

    /** @return string the Sylius order number */
    public function handle(Buyer $buyer, OrderRequestData $request, string $payloadId): string
    {
        if ($request->type !== 'new') {
            throw new OrderRequestRejected(sprintf('OrderRequest type "%s" is not supported', mb_substr($request->type, 0, 20)), CxmlStatus::OPERATION_FAILED);
        }
        if ($request->testMode) {
            throw new OrderRequestRejected('Test purchase orders (deploymentMode="test") are not accepted', CxmlStatus::OPERATION_FAILED);
        }
        if ($buyer->getOrderPaymentMethod() === null) {
            throw new OrderRequestRejected('OrderRequest is not enabled for this buyer', CxmlStatus::OPERATION_FAILED);
        }

        return $this->knownOrderNumber($buyer, $request) ?? $this->createOrder($buyer, $request, $payloadId);
    }

    private function createOrder(Buyer $buyer, OrderRequestData $request, string $payloadId): string
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->insert(self::TABLE, [
                'buyer_id' => $buyer->getId(), 'purchase_order' => $request->orderId, 'content_hash' => $request->contentHash(),
                'payload_id' => mb_substr($payloadId, 0, 255), 'created_at' => new \DateTimeImmutable(),
            ], ['created_at' => Types::DATETIME_IMMUTABLE]);
        } catch (UniqueConstraintViolationException) {
            // Claimed meanwhile by a concurrent repost.
            $connection->rollBack();

            return $this->knownOrderNumber($buyer, $request) ?? throw new OrderRequestRejected('Purchase order being processed, send it again later', CxmlStatus::OPERATION_FAILED);
        }

        try {
            $claim = (int) $connection->lastInsertId();
            $order = $this->orderBuilder->build($buyer, $request);
            $this->entityManager->persist($order);
            $this->entityManager->flush();
            $connection->update(self::TABLE, ['order_id' => $order->getId()], ['id' => $claim]);
            $connection->commit();

            return (string) $order->getNumber();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            $this->entityManager->clear();

            throw $exception;
        }
    }
}
