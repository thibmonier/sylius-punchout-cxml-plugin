<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/** US-003 / US-012: OCI username of a buyer, OCI context of a session. */
final class Version20261016000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tmonier_punchout_buyer.oci_username and tmonier_punchout_session.oci_context';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('tmonier_punchout_buyer')->addColumn('oci_username', Types::STRING, ['length' => 255, 'notnull' => false]);
        $schema->getTable('tmonier_punchout_session')->addColumn('oci_context', Types::JSON, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('tmonier_punchout_buyer')->dropColumn('oci_username');
        $schema->getTable('tmonier_punchout_session')->dropColumn('oci_context');
    }
}
