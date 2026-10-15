<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Unit\Cxml;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\Exception\CxmlParseException;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\OrderRequestReader;

final class OrderRequestReaderTest extends TestCase
{
    public function testItReadsTheHeaderAddressesAndItems(): void
    {
        $data = (new OrderRequestReader())->read($this->document('PO-1', '<ItemOut quantity="3" lineNumber="1"><ItemID><SupplierPartID>PART</SupplierPartID><SupplierPartAuxiliaryID>v1:VAR_1</SupplierPartAuxiliaryID></ItemID><ItemDetail><UnitPrice><Money currency="EUR">9.90</Money></UnitPrice></ItemDetail></ItemOut>'));

        self::assertSame(['PO-1', 'new', 'Approve quickly'], [$data->orderId, $data->type, $data->comments]);
        self::assertSame(['BigCorp', 'Claire Martin', ['12 rue de la Paix', 'Bât. B'], 'Paris', '75002', 'FR', 'IDF'], [$data->shipTo->name, $data->shipTo->deliverTo, $data->shipTo->street, $data->shipTo->city, $data->shipTo->postalCode, $data->shipTo->countryCode, $data->shipTo->state]);
        self::assertSame(['1', 'VAR_1', 3, '9.90', 'EUR'], [$data->items[0]->lineNumber, $data->items[0]->variantCode, $data->items[0]->quantity, $data->items[0]->unitPrice, $data->items[0]->currency]);
    }

    public function testThePartNumberIsUsedWithoutAuxiliaryId(): void
    {
        $data = (new OrderRequestReader())->read($this->document('PO-2', '<ItemOut quantity="1"><ItemID><SupplierPartID>PART-9</SupplierPartID></ItemID><ItemDetail><UnitPrice><Money currency="EUR">1</Money></UnitPrice></ItemDetail></ItemOut>'));

        self::assertSame('PART-9', $data->items[0]->variantCode);
    }

    public function testAPurchaseOrderWithoutItemIsRefused(): void
    {
        $this->expectException(CxmlParseException::class);
        (new OrderRequestReader())->read($this->document('PO-3', ''));
    }

    public function testAnInvalidPriceIsRefused(): void
    {
        $this->expectException(CxmlParseException::class);
        (new OrderRequestReader())->read($this->document('PO-4', '<ItemOut quantity="1"><ItemID><SupplierPartID>P</SupplierPartID></ItemID><ItemDetail><UnitPrice><Money currency="EUR">1,5</Money></UnitPrice></ItemDetail></ItemOut>'));
    }

    public function testTooManyItemsAreRefused(): void
    {
        $item = '<ItemOut quantity="1"><ItemID><SupplierPartID>P</SupplierPartID></ItemID><ItemDetail><UnitPrice><Money currency="EUR">1</Money></UnitPrice></ItemDetail></ItemOut>';

        $this->expectException(CxmlParseException::class);
        (new OrderRequestReader(2))->read($this->document('PO-5', str_repeat($item, 3)));
    }

    public function testAMissingOrderIdIsRefused(): void
    {
        $this->expectException(CxmlParseException::class);
        (new OrderRequestReader())->read($this->document('', '<ItemOut quantity="1"><ItemID><SupplierPartID>P</SupplierPartID></ItemID><ItemDetail><UnitPrice><Money currency="EUR">1</Money></UnitPrice></ItemDetail></ItemOut>'));
    }

    private function document(string $orderId, string $items): \DOMDocument
    {
        $address = '<Address isoCountryCode="FR"><Name xml:lang="fr">BigCorp</Name><PostalAddress><DeliverTo>Claire Martin</DeliverTo><Street>12 rue de la Paix</Street><Street>Bât. B</Street><City>Paris</City><State>IDF</State><PostalCode>75002</PostalCode><Country isoCountryCode="fr">France</Country></PostalAddress></Address>';
        $document = new \DOMDocument();
        $document->loadXML(sprintf(
            '<cXML><Request><OrderRequest><OrderRequestHeader orderID="%s" type="new"><ShipTo>%s</ShipTo><BillTo>%s</BillTo><Comments>Approve quickly</Comments></OrderRequestHeader>%s</OrderRequest></Request></cXML>',
            $orderId,
            $address,
            $address,
            $items,
        ));

        return $document;
    }
}
