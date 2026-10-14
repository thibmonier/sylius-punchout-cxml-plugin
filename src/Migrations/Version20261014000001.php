<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates all tmonier_punchout_* tables (Sprint 1, clean single migration).
 *
 * Uses the DBAL Schema API for MySQL / MariaDB / PostgreSQL portability.
 * Tables: buyer, buyer_identity, buyer_credential, session, message_log.
 *
 * R1: session.order_id (FK to sylius_order, SET NULL on delete) — isolated PunchOut cart.
 * R4: session.from_domain, session.from_identity — buyer identities for cXML From header.
 * R5: buyer.tax_included removed — neutral adjustment detection replaces the flag.
 */
final class Version20261014000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tmonier_punchout_* tables — Sprint 1 (R1/R4/R5, Schema API, portable)';
    }

    public function up(Schema $schema): void
    {
        // --- tmonier_punchout_buyer ---
        $buyer = $schema->createTable('tmonier_punchout_buyer');
        $buyer->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
        $buyer->addColumn('customer_id', Types::INTEGER, ['notnull' => false]);
        $buyer->addColumn('channel_id', Types::INTEGER, ['notnull' => false]);
        $buyer->addColumn('code', Types::STRING, ['length' => 255, 'notnull' => true]);
        $buyer->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
        $buyer->addColumn('protocol', Types::STRING, ['length' => 10, 'notnull' => true]);
        $buyer->addColumn('enabled', Types::BOOLEAN, ['notnull' => true]);
        $buyer->addColumn('company_id', Types::STRING, ['length' => 255, 'notnull' => false]);
        $buyer->addColumn('return_domains', Types::JSON, ['notnull' => true]);
        $buyer->addColumn('iframe_origins', Types::JSON, ['notnull' => true]);
        $buyer->addColumn('locale', Types::STRING, ['length' => 20, 'notnull' => false]);
        // NOTE: tax_included intentionally omitted (R5: neutral adjustment detection)
        $buyer->addColumn('include_shipping', Types::BOOLEAN, ['notnull' => false]);
        $buyer->setPrimaryKey(['id']);
        $buyer->addUniqueIndex(['code'], 'uniq_punchout_buyer_code');
        $buyer->addIndex(['customer_id'], 'IDX_259EA9EF9395C3F3');
        $buyer->addIndex(['channel_id'], 'IDX_259EA9EF72F5A1AA');
        $buyer->addForeignKeyConstraint(
            'sylius_customer',
            ['customer_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'FK_259EA9EF9395C3F3',
        );
        $buyer->addForeignKeyConstraint(
            'sylius_channel',
            ['channel_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'FK_259EA9EF72F5A1AA',
        );

        // --- tmonier_punchout_buyer_identity ---
        $identity = $schema->createTable('tmonier_punchout_buyer_identity');
        $identity->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
        $identity->addColumn('buyer_id', Types::INTEGER, ['notnull' => true]);
        $identity->addColumn('role', Types::STRING, ['length' => 10, 'notnull' => true]);
        $identity->addColumn('domain', Types::STRING, ['length' => 255, 'notnull' => true]);
        $identity->addColumn('identity', Types::STRING, ['length' => 255, 'notnull' => true]);
        $identity->setPrimaryKey(['id']);
        $identity->addIndex(['buyer_id'], 'IDX_FC4E87DD6C755722');
        $identity->addUniqueIndex(['role', 'domain', 'identity'], 'uniq_buyer_identity');
        $identity->addForeignKeyConstraint(
            'tmonier_punchout_buyer',
            ['buyer_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'FK_FC4E87DD6C755722',
        );

        // --- tmonier_punchout_buyer_credential ---
        $credential = $schema->createTable('tmonier_punchout_buyer_credential');
        $credential->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
        $credential->addColumn('buyer_id', Types::INTEGER, ['notnull' => true]);
        $credential->addColumn('label', Types::STRING, ['length' => 255, 'notnull' => true]);
        $credential->addColumn('password_hash', Types::STRING, ['length' => 255, 'notnull' => true]);
        $credential->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $credential->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $credential->setPrimaryKey(['id']);
        $credential->addIndex(['buyer_id'], 'IDX_34756ED76C755722');
        $credential->addForeignKeyConstraint(
            'tmonier_punchout_buyer',
            ['buyer_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'FK_34756ED76C755722',
        );

        // --- tmonier_punchout_session ---
        $session = $schema->createTable('tmonier_punchout_session');
        $session->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
        $session->addColumn('buyer_id', Types::INTEGER, ['notnull' => false]);
        // R1: isolated PunchOut cart — SET NULL on order delete (order can outlive session)
        $session->addColumn('order_id', Types::INTEGER, ['notnull' => false]);
        $session->addColumn('operation', Types::STRING, ['length' => 20, 'notnull' => true]);
        $session->addColumn('buyer_cookie', Types::STRING, ['length' => 1024, 'notnull' => true]);
        $session->addColumn('return_url', Types::STRING, ['length' => 2048, 'notnull' => true]);
        $session->addColumn('start_token_hash', Types::STRING, ['length' => 64, 'notnull' => false]);
        $session->addColumn('start_token_expiry', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $session->addColumn('status', Types::STRING, ['length' => 20, 'notnull' => true]);
        $session->addColumn('extrinsics', Types::JSON, ['notnull' => true]);
        $session->addColumn('items_out', Types::JSON, ['notnull' => true]);
        // R4: preserve buyer From identities for cXML PunchOutOrderMessage header
        $session->addColumn('from_domain', Types::STRING, ['length' => 255, 'notnull' => true]);
        $session->addColumn('from_identity', Types::STRING, ['length' => 255, 'notnull' => true]);
        $session->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $session->addColumn('updated_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $session->setPrimaryKey(['id']);
        $session->addIndex(['buyer_id'], 'IDX_929431AB6C755722');
        $session->addIndex(['order_id'], 'IDX_929431AB8D9F6D38');
        $session->addIndex(['start_token_hash', 'status'], 'idx_punchout_session_token_status');
        $session->addIndex(['status', 'created_at'], 'idx_punchout_session_status_created');
        $session->addForeignKeyConstraint(
            'tmonier_punchout_buyer',
            ['buyer_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'FK_929431AB6C755722',
        );
        $session->addForeignKeyConstraint(
            'sylius_order',
            ['order_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'FK_929431AB8D9F6D38',
        );

        // --- tmonier_punchout_message_log ---
        $log = $schema->createTable('tmonier_punchout_message_log');
        $log->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
        $log->addColumn('buyer_id', Types::INTEGER, ['notnull' => false]);
        $log->addColumn('session_id', Types::INTEGER, ['notnull' => false]);
        $log->addColumn('direction', Types::STRING, ['length' => 3, 'notnull' => true]);
        $log->addColumn('protocol', Types::STRING, ['length' => 10, 'notnull' => true]);
        $log->addColumn('message_type', Types::STRING, ['length' => 100, 'notnull' => true]);
        $log->addColumn('payload_id', Types::STRING, ['length' => 255, 'notnull' => true]);
        $log->addColumn('status', Types::INTEGER, ['notnull' => true]);
        $log->addColumn('body_hash', Types::STRING, ['length' => 64, 'notnull' => false]);
        $log->addColumn('masked_body', Types::TEXT, ['notnull' => false]);
        $log->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $log->setPrimaryKey(['id']);
        $log->addIndex(['buyer_id'], 'IDX_2257CF3C6C755722');
        $log->addIndex(['session_id'], 'IDX_2257CF3C613FECDF');
        $log->addIndex(['created_at'], 'idx_message_log_created_at');
        $log->addIndex(['buyer_id', 'created_at'], 'idx_message_log_buyer_date');
        $log->addForeignKeyConstraint(
            'tmonier_punchout_buyer',
            ['buyer_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'FK_2257CF3C6C755722',
        );
        $log->addForeignKeyConstraint(
            'tmonier_punchout_session',
            ['session_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'FK_2257CF3C613FECDF',
        );
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('tmonier_punchout_message_log');
        $schema->dropTable('tmonier_punchout_session');
        $schema->dropTable('tmonier_punchout_buyer_credential');
        $schema->dropTable('tmonier_punchout_buyer_identity');
        $schema->dropTable('tmonier_punchout_buyer');
    }
}
