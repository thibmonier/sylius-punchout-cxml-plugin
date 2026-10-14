<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Align tmonier_punchout_buyer schema with the Buyer entity (Sprint 1 correction).
 *
 * Version20261014000001 created the table with a simplified schema (allowed_return_domains,
 * channel_code, notes, created_at, updated_at). The actual Buyer entity uses a richer schema
 * with proper FK relations to sylius_customer and sylius_channel, a unique code, separate
 * return_domains and iframe_origins JSON arrays, and optional per-buyer overrides for
 * locale, tax_included and include_shipping.
 *
 * This migration recreates the table with the correct schema.
 */
final class Version20261014000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align tmonier_punchout_buyer with Buyer entity (drop legacy columns, add FK relations)';
    }

    public function up(Schema $schema): void
    {
        // Drop dependent tables first (FK constraints)
        $this->addSql('DROP TABLE IF EXISTS tmonier_punchout_message_log');
        $this->addSql('DROP TABLE IF EXISTS tmonier_punchout_session');
        $this->addSql('DROP TABLE IF EXISTS tmonier_punchout_buyer_credential');
        $this->addSql('DROP TABLE IF EXISTS tmonier_punchout_buyer_identity');
        $this->addSql('DROP TABLE IF EXISTS tmonier_punchout_buyer');

        $this->addSql(<<<'SQL'
            CREATE TABLE tmonier_punchout_buyer (
                id INT AUTO_INCREMENT NOT NULL,
                customer_id INT DEFAULT NULL,
                channel_id INT DEFAULT NULL,
                code VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                protocol VARCHAR(10) NOT NULL DEFAULT 'cxml',
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                company_id VARCHAR(255) DEFAULT NULL,
                return_domains JSON NOT NULL,
                iframe_origins JSON NOT NULL,
                locale VARCHAR(20) DEFAULT NULL,
                tax_included TINYINT(1) DEFAULT NULL,
                include_shipping TINYINT(1) DEFAULT NULL,
                CONSTRAINT FK_punchout_buyer_customer FOREIGN KEY (customer_id)
                    REFERENCES sylius_customer (id) ON DELETE SET NULL,
                CONSTRAINT FK_punchout_buyer_channel FOREIGN KEY (channel_id)
                    REFERENCES sylius_channel (id) ON DELETE SET NULL,
                INDEX idx_punchout_buyer_customer (customer_id),
                INDEX idx_punchout_buyer_channel (channel_id),
                UNIQUE INDEX uniq_punchout_buyer_code (code),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE tmonier_punchout_buyer_identity (
                id INT AUTO_INCREMENT NOT NULL,
                buyer_id INT NOT NULL,
                role VARCHAR(10) NOT NULL,
                domain VARCHAR(255) NOT NULL,
                identity VARCHAR(255) NOT NULL,
                CONSTRAINT FK_punchout_identity_buyer FOREIGN KEY (buyer_id)
                    REFERENCES tmonier_punchout_buyer (id) ON DELETE CASCADE,
                UNIQUE INDEX uniq_buyer_identity (buyer_id, role, domain, identity),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE tmonier_punchout_buyer_credential (
                id INT AUTO_INCREMENT NOT NULL,
                buyer_id INT NOT NULL,
                label VARCHAR(255) NOT NULL DEFAULT '',
                password_hash VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                CONSTRAINT FK_punchout_credential_buyer FOREIGN KEY (buyer_id)
                    REFERENCES tmonier_punchout_buyer (id) ON DELETE CASCADE,
                INDEX idx_credential_buyer (buyer_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE tmonier_punchout_session (
                id INT AUTO_INCREMENT NOT NULL,
                buyer_id INT DEFAULT NULL,
                operation VARCHAR(20) NOT NULL DEFAULT 'create',
                buyer_cookie VARCHAR(1024) NOT NULL,
                return_url VARCHAR(2048) NOT NULL,
                start_token_hash VARCHAR(64) DEFAULT NULL,
                start_token_expiry DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                extrinsics JSON NOT NULL,
                items_out JSON NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                CONSTRAINT FK_punchout_session_buyer FOREIGN KEY (buyer_id)
                    REFERENCES tmonier_punchout_buyer (id) ON DELETE SET NULL,
                INDEX idx_punchout_session_token_status (start_token_hash, status),
                INDEX idx_punchout_session_status_created (status, created_at),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE tmonier_punchout_message_log (
                id INT AUTO_INCREMENT NOT NULL,
                buyer_id INT DEFAULT NULL,
                session_id INT DEFAULT NULL,
                direction VARCHAR(3) NOT NULL,
                protocol VARCHAR(10) NOT NULL DEFAULT 'cxml',
                message_type VARCHAR(100) NOT NULL,
                payload_id VARCHAR(255) NOT NULL,
                status INT NOT NULL,
                body_hash VARCHAR(64) DEFAULT NULL,
                masked_body LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                CONSTRAINT FK_punchout_log_buyer FOREIGN KEY (buyer_id)
                    REFERENCES tmonier_punchout_buyer (id) ON DELETE SET NULL,
                CONSTRAINT FK_punchout_log_session FOREIGN KEY (session_id)
                    REFERENCES tmonier_punchout_session (id) ON DELETE SET NULL,
                INDEX idx_message_log_created_at (created_at),
                INDEX idx_message_log_buyer_date (buyer_id, created_at),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS tmonier_punchout_message_log');
        $this->addSql('DROP TABLE IF EXISTS tmonier_punchout_session');
        $this->addSql('DROP TABLE IF EXISTS tmonier_punchout_buyer_credential');
        $this->addSql('DROP TABLE IF EXISTS tmonier_punchout_buyer_identity');
        $this->addSql('DROP TABLE IF EXISTS tmonier_punchout_buyer');
    }
}
