<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cxml;

use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\TransferLine;

/**
 * Writes a cXML 1.2.071 PunchOutOrderMessage document.
 *
 * - DOCTYPE PUBLIC/SYSTEM: cXML 1.2.071
 * - No SharedSecret in the output (buyer message, not an auth token)
 * - operationAllowed: "edit" for create/edit, "inspect" for inspect
 * - timestamp: ISO 8601 with UTC offset, never "Z"
 */
final class OrderMessageWriter
{
    private const DOCTYPE_PUBLIC = '-//cXML.org//DTD cXML 1.2//EN';

    private const DOCTYPE_SYSTEM = 'http://xml.cxml.org/schemas/cXML/1.2.071/cXML.dtd';

    private const CXML_VERSION = '1.2.071';

    /**
     * @param list<TransferLine> $lines
     */
    public function write(
        array $lines,
        string $total,
        string $currency,
        string $buyerCookie,
        string $payloadId,
        string $operation,
        string $supplierDomain = 'NetworkId',
        string $supplierIdentity = 'supplier',
        string $buyerDomain = 'NetworkId',
        string $buyerIdentity = 'buyer',
    ): string {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $cxml = $doc->createElement('cXML');
        $cxml->setAttribute('version', self::CXML_VERSION);
        $cxml->setAttribute('payloadID', $payloadId);
        $cxml->setAttribute('timestamp', $this->timestamp());
        $doc->appendChild($cxml);

        // Header (required by cXML DTD)
        $header = $doc->createElement('Header');
        $cxml->appendChild($header);

        $from = $doc->createElement('From');
        $header->appendChild($from);
        $this->appendCredential($doc, $from, $supplierDomain, $supplierIdentity);

        $to = $doc->createElement('To');
        $header->appendChild($to);
        $this->appendCredential($doc, $to, $buyerDomain, $buyerIdentity);

        $sender = $doc->createElement('Sender');
        $header->appendChild($sender);
        $this->appendCredential($doc, $sender, $supplierDomain, $supplierIdentity);
        $userAgent = $doc->createElement('UserAgent', 'tmonier/sylius-punchout-cxml-plugin');
        $sender->appendChild($userAgent);

        // Message/PunchOutOrderMessage
        $message = $doc->createElement('Message');
        $cxml->appendChild($message);

        $poom = $doc->createElement('PunchOutOrderMessage');
        $message->appendChild($poom);

        $cookieEl = $doc->createElement('BuyerCookie');
        $cookieEl->textContent = $buyerCookie;
        $poom->appendChild($cookieEl);

        $poomHeader = $doc->createElement('PunchOutOrderMessageHeader');
        $poomHeader->setAttribute('operationAllowed', $operation === 'inspect' ? 'inspect' : 'edit');
        $poom->appendChild($poomHeader);

        $totalEl = $doc->createElement('Total');
        $poomHeader->appendChild($totalEl);

        $moneyEl = $doc->createElement('Money');
        $moneyEl->setAttribute('currency', $currency);
        $moneyEl->textContent = $total;
        $totalEl->appendChild($moneyEl);

        // ItemIn elements
        foreach ($lines as $line) {
            $poom->appendChild($this->buildItemIn($doc, $line));
        }

        return $this->prependDoctype($doc->saveXML() ?: '');
    }

    private function appendCredential(\DOMDocument $doc, \DOMElement $parent, string $domain, string $identity): void
    {
        $credential = $doc->createElement('Credential');
        $credential->setAttribute('domain', $domain);
        $parent->appendChild($credential);

        $identityEl = $doc->createElement('Identity');
        $identityEl->textContent = $identity;
        $credential->appendChild($identityEl);
    }

    private function buildItemIn(\DOMDocument $doc, TransferLine $line): \DOMElement
    {
        $itemIn = $doc->createElement('ItemIn');
        $itemIn->setAttribute('quantity', (string) $line->quantity);

        $itemId = $doc->createElement('ItemID');
        $itemIn->appendChild($itemId);

        $supplierPartId = $doc->createElement('SupplierPartID');
        $supplierPartId->textContent = $line->variantCode;
        $itemId->appendChild($supplierPartId);

        $auxId = $doc->createElement('SupplierPartAuxiliaryID');
        $auxId->textContent = $line->supplierPartAuxId;
        $itemId->appendChild($auxId);

        $itemDetail = $doc->createElement('ItemDetail');
        $itemIn->appendChild($itemDetail);

        $unitPrice = $doc->createElement('UnitPrice');
        $itemDetail->appendChild($unitPrice);

        $money = $doc->createElement('Money');
        $money->setAttribute('currency', $line->currency);
        $money->textContent = $line->unitNetPrice;
        $unitPrice->appendChild($money);

        $desc = $doc->createElement('Description');
        $desc->setAttribute('xml:lang', 'en');
        $desc->textContent = $line->description;
        $itemDetail->appendChild($desc);

        $uom = $doc->createElement('UnitOfMeasure');
        $uom->textContent = $line->unitOfMeasure;
        $itemDetail->appendChild($uom);

        // Classification+ is required by the cXML 1.2.071 DTD.
        // Fall back to UNSPSC "00000000" (unclassified) when not provided.
        $classification = $doc->createElement('Classification');
        $classification->setAttribute('domain', 'UNSPSC');
        $classification->textContent = $line->unspsc ?? '00000000';
        $itemDetail->appendChild($classification);

        return $itemIn;
    }

    private function timestamp(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:sP');
    }

    private function prependDoctype(string $xml): string
    {
        if (!str_starts_with($xml, '<?xml')) {
            return $xml;
        }

        $nl = strpos($xml, "\n");
        if ($nl === false) {
            return $xml;
        }

        $declaration = substr($xml, 0, $nl);
        $rest = substr($xml, $nl);
        $doctype = sprintf(
            "\n<!DOCTYPE cXML PUBLIC \"%s\" \"%s\">",
            self::DOCTYPE_PUBLIC,
            self::DOCTYPE_SYSTEM,
        );

        return $declaration . $doctype . $rest;
    }
}
