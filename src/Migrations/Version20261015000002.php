<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/** US-019: the buyer chooses whether the tax of the cart is sent back. */
final class Version20261015000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tmonier_punchout_buyer.include_tax';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('tmonier_punchout_buyer')->addColumn('include_tax', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('tmonier_punchout_buyer')->dropColumn('include_tax');
    }
}
