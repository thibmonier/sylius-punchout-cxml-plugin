<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cxml;

use Tmonier\SyliusPunchoutCxmlPlugin\Cart\CartRebuilder;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\Exception\CxmlParseException;

/**
 * Reads an OrderRequest (US-030): the header (orderID, type, ShipTo and BillTo addresses, comments)
 * and its ItemOut, at most `max_items_out`. Line-level ShipTo are not read (ADR-0008).
 */
final readonly class OrderRequestReader
{
    private const HEADER = '/cXML/Request/OrderRequest/OrderRequestHeader';

    public function __construct(private int $maxItemsOut = 500)
    {
    }

    public function read(\DOMDocument $doc): OrderRequestData
    {
        $xpath = new \DOMXPath($doc);
        $header = $this->element($xpath, self::HEADER);
        $orderId = trim($header->getAttribute('orderID'));
        if ($orderId === '' || mb_strlen($orderId) > 100) {
            throw new CxmlParseException('orderID is missing or longer than 100 characters');
        }
        $items = $xpath->query('/cXML/Request/OrderRequest/ItemOut');
        if ($items === false || $items->length === 0) {
            throw new CxmlParseException('The OrderRequest has no ItemOut');
        }
        if ($items->length > $this->maxItemsOut) {
            throw new CxmlParseException(sprintf('Too many ItemOut (%d at most)', $this->maxItemsOut));
        }

        return new OrderRequestData(
            $orderId,
            $header->getAttribute('type') !== '' ? $header->getAttribute('type') : 'new',
            $this->address($xpath, $this->element($xpath, self::HEADER . '/ShipTo/Address')),
            $this->address($xpath, $this->element($xpath, self::HEADER . '/BillTo/Address')),
            array_map(fn (\DOMElement $item): OrderRequestItem => $this->item($xpath, $item), $this->elements($items)),
            mb_substr($this->text($xpath, self::HEADER . '/Comments'), 0, 1000),
        );
    }

    private function item(\DOMXPath $xpath, \DOMElement $item): OrderRequestItem
    {
        $money = $xpath->query('ItemDetail/UnitPrice/Money', $item);
        $moneyElement = $money === false ? null : $money->item(0);
        $price = $moneyElement instanceof \DOMElement ? trim($moneyElement->textContent) : '';
        if (!$moneyElement instanceof \DOMElement || 1 !== preg_match('/^-?\d+(\.\d+)?$/', $price)) {
            throw new CxmlParseException('Missing or invalid UnitPrice');
        }

        return new OrderRequestItem(
            $item->getAttribute('lineNumber'),
            CartRebuilder::variantCode([
                'supplierPartId' => $this->text($xpath, 'ItemID/SupplierPartID', $item),
                'supplierPartAuxId' => $this->text($xpath, 'ItemID/SupplierPartAuxiliaryID', $item),
            ]),
            max(0, (int) $item->getAttribute('quantity')),
            $price,
            $moneyElement->getAttribute('currency'),
        );
    }

    private function address(\DOMXPath $xpath, \DOMElement $address): CxmlAddress
    {
        $streets = $xpath->query('PostalAddress/Street', $address);
        $street = array_values(array_filter(array_map(static fn (\DOMElement $node): string => trim($node->textContent), $streets === false ? [] : $this->elements($streets))));
        $country = $this->text($xpath, 'PostalAddress/Country/@isoCountryCode', $address);
        $country = $country !== '' ? $country : $address->getAttribute('isoCountryCode');
        $state = $this->text($xpath, 'PostalAddress/State', $address);

        return new CxmlAddress(
            $this->text($xpath, 'Name', $address),
            $this->text($xpath, 'PostalAddress/DeliverTo', $address),
            $street,
            $this->text($xpath, 'PostalAddress/City', $address),
            $this->text($xpath, 'PostalAddress/PostalCode', $address),
            strtoupper($country),
            $state !== '' ? $state : null,
        );
    }

    private function element(\DOMXPath $xpath, string $query): \DOMElement
    {
        $nodes = $xpath->query($query);
        $node = $nodes === false ? null : $nodes->item(0);
        if (!$node instanceof \DOMElement) {
            throw new CxmlParseException(sprintf('Missing %s', $query));
        }

        return $node;
    }

    private function text(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): string
    {
        $nodes = $xpath->query($query, $context);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof \DOMNode ? trim($node->textContent) : '';
    }

    /**
     * @param \DOMNodeList<\DOMNameSpaceNode|\DOMNode> $nodes
     *
     * @return list<\DOMElement>
     */
    private function elements(\DOMNodeList $nodes): array
    {
        $elements = [];
        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }
}
