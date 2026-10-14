<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cxml;

use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\Exception\CxmlParseException;

/**
 * Reads a parsed PunchOutSetupRequest DOMDocument and returns a SetupRequestData DTO.
 */
final readonly class SetupRequestReader
{
    /**
     * @param list<string> $allowedExtrinsics
     */
    public function __construct(private array $allowedExtrinsics = [])
    {
    }

    public function read(\DOMDocument $doc): SetupRequestData
    {
        $xpath = new \DOMXPath($doc);

        // Root cXML element
        $cxmlNodes = $doc->getElementsByTagName('cXML');
        if ($cxmlNodes->length === 0) {
            throw new CxmlParseException('Missing root cXML element');
        }

        $documentElement = $doc->documentElement;
        if (!$documentElement instanceof \DOMElement) {
            throw new CxmlParseException('Missing document element');
        }

        $payloadId = $this->requireAttribute($documentElement, 'payloadID');
        $timestamp = $this->requireAttribute($documentElement, 'timestamp');

        // From identity
        $fromDomain = $this->requireTextContent($xpath, '//Header/From/Credential/@domain');
        $fromIdentity = $this->requireTextContent($xpath, '//Header/From/Credential/Identity');

        // Sender identity
        $senderDomain = $this->requireTextContent($xpath, '//Header/Sender/Credential/@domain');
        $senderIdentity = $this->requireTextContent($xpath, '//Header/Sender/Credential/Identity');
        $sharedSecret = $this->textContent($xpath, '//Header/Sender/Credential/SharedSecret') ?? '';

        // Request body
        $setupRequest = $xpath->query('//Request/PunchOutSetupRequest');
        if ($setupRequest === false || $setupRequest->length === 0) {
            throw new CxmlParseException('Missing PunchOutSetupRequest element');
        }

        /** @var \DOMElement $setupEl */
        $setupEl = $setupRequest->item(0);
        $operation = $setupEl->getAttribute('operation');
        if (!in_array($operation, ['create', 'edit', 'inspect'], true)) {
            throw new CxmlParseException(sprintf('Unknown operation "%s"', $operation));
        }

        $buyerCookie = $this->requireTextContent($xpath, '//Request/PunchOutSetupRequest/BuyerCookie');
        $browserFormPostUrl = $this->requireTextContent($xpath, '//Request/PunchOutSetupRequest/BrowserFormPost/URL');

        // Extrinsics (filtered by allowed list)
        $extrinsics = [];
        $extrinsicNodes = $xpath->query('//Header/Extrinsic');
        if ($extrinsicNodes !== false) {
            foreach ($extrinsicNodes as $node) {
                /** @var \DOMElement $node */
                $name = $node->getAttribute('name');
                if ($this->allowedExtrinsics === [] || in_array($name, $this->allowedExtrinsics, true)) {
                    $extrinsics[$name] = trim($node->textContent);
                }
            }
        }

        // ItemOut (edit / inspect)
        $itemsOut = [];
        $itemOutNodes = $xpath->query('//Request/PunchOutSetupRequest/ItemOut');
        if ($itemOutNodes !== false) {
            foreach ($itemOutNodes as $node) {
                /** @var \DOMElement $node */
                $itemsOut[] = $this->parseItemOut($node, $xpath);
            }
        }

        return new SetupRequestData(
            operation: $operation,
            payloadId: $payloadId,
            timestamp: $timestamp,
            fromDomain: $fromDomain,
            fromIdentity: $fromIdentity,
            senderDomain: $senderDomain,
            senderIdentity: $senderIdentity,
            sharedSecret: $sharedSecret,
            buyerCookie: $buyerCookie,
            browserFormPostUrl: $browserFormPostUrl,
            extrinsics: $extrinsics,
            itemsOut: $itemsOut,
        );
    }

    private function requireAttribute(\DOMElement $el, string $name): string
    {
        $value = $el->getAttribute($name);
        if ($value === '') {
            throw new CxmlParseException(sprintf('Required attribute "%s" is missing or empty', $name));
        }

        return $value;
    }

    private function requireTextContent(\DOMXPath $xpath, string $query): string
    {
        $value = $this->textContent($xpath, $query);
        if ($value === null || $value === '') {
            throw new CxmlParseException(sprintf('Required element/attribute "%s" is missing or empty', $query));
        }

        return $value;
    }

    private function textContent(\DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);
        if (!$node instanceof \DOMNode) {
            return null;
        }

        return trim($node->textContent);
    }

    /** @return array<string, mixed> */
    private function parseItemOut(\DOMElement $itemOut, \DOMXPath $xpath): array
    {
        $item = [
            'quantity' => (int) $itemOut->getAttribute('quantity'),
            'supplierPartId' => null,
            'supplierPartAuxId' => null,
        ];

        $idNodes = $xpath->query('.//ItemID/SupplierPartID', $itemOut);
        if ($idNodes !== false && $idNodes->length > 0) {
            $node = $idNodes->item(0);
            $item['supplierPartId'] = $node instanceof \DOMNode ? trim($node->textContent) : '';
        }

        $auxNodes = $xpath->query('.//ItemID/SupplierPartAuxiliaryID', $itemOut);
        if ($auxNodes !== false && $auxNodes->length > 0) {
            $node = $auxNodes->item(0);
            $item['supplierPartAuxId'] = $node instanceof \DOMNode ? trim($node->textContent) : '';
        }

        return $item;
    }
}
