<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tmonier_punchout_* tables (Sprint 1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE tmonier_punchout_buyer (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(255) NOT NULL,
                protocol VARCHAR(10) NOT NULL DEFAULT 'cxml',
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                allowed_return_domains JSON NOT NULL,
                channel_code VARCHAR(255) DEFAULT NULL,
                notes LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
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
