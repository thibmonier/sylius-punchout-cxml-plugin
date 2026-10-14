<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/** US-005: UNSPSC codes and units of products and taxons, buyer defaults. */
final class Version20261015000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tmonier_punchout_{taxon,product}_classification, add buyer default unit and UNSPSC';
    }

    public function up(Schema $schema): void
    {
        $taxon = $schema->createTable('tmonier_punchout_taxon_classification');
        $taxon->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
        $taxon->addColumn('taxon_id', Types::INTEGER, ['notnull' => true]);
        $taxon->addColumn('unspsc', Types::STRING, ['length' => 16, 'notnull' => false]);
        $taxon->setPrimaryKey(['id']);
        $taxon->addUniqueIndex(['taxon_id'], 'UNIQ_4DCF3662DE13F470');
        $taxon->addForeignKeyConstraint('sylius_taxon', ['taxon_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_PUNCHOUT_TAXON_CLASS_TAXON');

        $product = $schema->createTable('tmonier_punchout_product_classification');
        $product->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
        $product->addColumn('product_id', Types::INTEGER, ['notnull' => true]);
        $product->addColumn('unspsc', Types::STRING, ['length' => 16, 'notnull' => false]);
        $product->addColumn('unit', Types::STRING, ['length' => 8, 'notnull' => false]);
        $product->setPrimaryKey(['id']);
        $product->addUniqueIndex(['product_id'], 'UNIQ_276C61F54584665A');
        $product->addForeignKeyConstraint('sylius_product', ['product_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_PUNCHOUT_PRODUCT_CLASS_PRODUCT');

        $buyer = $schema->getTable('tmonier_punchout_buyer');
        $buyer->addColumn('default_unit', Types::STRING, ['length' => 8, 'notnull' => true, 'default' => 'EA']);
        $buyer->addColumn('default_unspsc', Types::STRING, ['length' => 16, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('tmonier_punchout_product_classification');
        $schema->dropTable('tmonier_punchout_taxon_classification');
        $buyer = $schema->getTable('tmonier_punchout_buyer');
        $buyer->dropColumn('default_unit');
        $buyer->dropColumn('default_unspsc');
    }
}
