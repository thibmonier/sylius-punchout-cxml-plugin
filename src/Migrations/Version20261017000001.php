<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/** US-030: OrderRequests received (one per buyer and orderID), payment and shipping methods of a buyer. */
final class Version20261017000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tmonier_punchout_order_request, add buyer order payment and shipping methods';
    }

    public function up(Schema $schema): void
    {
        $buyer = $schema->getTable('tmonier_punchout_buyer');
        $buyer->addColumn('order_payment_method', Types::STRING, ['length' => 255, 'notnull' => false]);
        $buyer->addColumn('order_shipping_method', Types::STRING, ['length' => 255, 'notnull' => false]);

        $table = $schema->createTable('tmonier_punchout_order_request');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('buyer_id', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('purchase_order', Types::STRING, ['length' => 100, 'notnull' => true]);
        $table->addColumn('order_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('content_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('payload_id', Types::STRING, ['length' => 255, 'notnull' => true]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['buyer_id', 'purchase_order'], 'uniq_punchout_order_request_po');
        $table->addIndex(['order_id'], 'IDX_BF8718E88D9F6D38');
        $table->addForeignKeyConstraint('tmonier_punchout_buyer', ['buyer_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_PUNCHOUT_ORDER_REQUEST_BUYER');
        $table->addForeignKeyConstraint('sylius_order', ['order_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_PUNCHOUT_ORDER_REQUEST_ORDER');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('tmonier_punchout_order_request');
        $buyer = $schema->getTable('tmonier_punchout_buyer');
        $buyer->dropColumn('order_payment_method');
        $buyer->dropColumn('order_shipping_method');
    }
}
