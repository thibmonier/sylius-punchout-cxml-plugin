<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/** Anti-replay of setup requests (US-008): payloadIDs already accepted per buyer. */
final class Version20261015000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tmonier_punchout_seen_payload (anti-replay)';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('tmonier_punchout_seen_payload');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('buyer_id', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('payload_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE, ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['buyer_id', 'payload_hash'], 'uniq_punchout_seen_payload');
        $table->addIndex(['expires_at'], 'idx_punchout_seen_payload_expires');
        $table->addIndex(['buyer_id'], 'IDX_94F2D7D46C755722');
        $table->addForeignKeyConstraint('tmonier_punchout_buyer', ['buyer_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_PUNCHOUT_SEEN_PAYLOAD_BUYER');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('tmonier_punchout_seen_payload');
    }
}
