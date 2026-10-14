<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cxml;

use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\Exception\CxmlParseException;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\Exception\UnsupportedOperationException;

/**
 * Reads a parsed PunchOutSetupRequest DOMDocument and returns a SetupRequestData DTO.
 */
final readonly class SetupRequestReader
{
    /**
     * @param list<string> $allowedExtrinsics
     */
    public function __construct(
        private array $allowedExtrinsics = [],
        private int $maxItemsOut = 500,
    ) {
    }

    /** Name of the request element (PunchOutSetupRequest, ProfileRequest, OrderRequest…). */
    public function requestName(\DOMDocument $doc): string
    {
        $request = (new \DOMXPath($doc))->query('/cXML/Request/*');
        $element = $request === false ? null : $request->item(0);
        if (!$element instanceof \DOMElement) {
            throw new CxmlParseException('Missing Request element');
        }

        return $element->nodeName;
    }

    public function readHeader(\DOMDocument $doc): CxmlHeader
    {
        $xpath = new \DOMXPath($doc);
        $documentElement = $doc->documentElement;
        if (!$documentElement instanceof \DOMElement || $documentElement->nodeName !== 'cXML') {
            throw new CxmlParseException('Missing root cXML element');
        }

        return new CxmlHeader(
            payloadId: $this->requireAttribute($documentElement, 'payloadID'),
            timestamp: $this->requireAttribute($documentElement, 'timestamp'),
            fromDomain: $this->requireTextContent($xpath, '/cXML/Header/From/Credential/@domain'),
            fromIdentity: $this->requireTextContent($xpath, '/cXML/Header/From/Credential/Identity'),
            senderDomain: $this->requireTextContent($xpath, '/cXML/Header/Sender/Credential/@domain'),
            senderIdentity: $this->requireTextContent($xpath, '/cXML/Header/Sender/Credential/Identity'),
            sharedSecret: $this->textContent($xpath, '/cXML/Header/Sender/Credential/SharedSecret') ?? '',
        );
    }

    public function read(\DOMDocument $doc): SetupRequestData
    {
        $xpath = new \DOMXPath($doc);
        $header = $this->readHeader($doc);
        $payloadId = $header->payloadId;
        $timestamp = $header->timestamp;
        $fromDomain = $header->fromDomain;
        $fromIdentity = $header->fromIdentity;
        $senderDomain = $header->senderDomain;
        $senderIdentity = $header->senderIdentity;
        $sharedSecret = $header->sharedSecret;

        // Request body
        $setupRequest = $xpath->query('/cXML/Request/PunchOutSetupRequest');
        if ($setupRequest === false || $setupRequest->length === 0) {
            throw new CxmlParseException('Missing PunchOutSetupRequest element');
        }

        /** @var \DOMElement $setupEl */
        $setupEl = $setupRequest->item(0);
        $operation = $setupEl->getAttribute('operation');
        if (!in_array($operation, ['create', 'edit', 'inspect'], true)) {
            throw new UnsupportedOperationException(sprintf('Unsupported operation "%s"', $operation));
        }

        $buyerCookie = $this->requireTextContent($xpath, '/cXML/Request/PunchOutSetupRequest/BuyerCookie');
        $browserFormPostUrl = $this->requireTextContent($xpath, '/cXML/Request/PunchOutSetupRequest/BrowserFormPost/URL');

        // Extrinsics of the setup request, only the allowed ones (personal data minimised)
        $extrinsics = [];
        $extrinsicNodes = $xpath->query('/cXML/Request/PunchOutSetupRequest/Extrinsic');
        if ($extrinsicNodes !== false) {
            foreach ($extrinsicNodes as $node) {
                /** @var \DOMElement $node */
                $name = $node->getAttribute('name');
                if (in_array($name, $this->allowedExtrinsics, true)) {
                    $extrinsics[$name] = trim($node->textContent);
                }
            }
        }

        // ItemOut (edit / inspect)
        $itemsOut = [];
        $itemOutNodes = $xpath->query('/cXML/Request/PunchOutSetupRequest/ItemOut');
        if ($itemOutNodes !== false && $itemOutNodes->length > $this->maxItemsOut) {
            throw new CxmlParseException(sprintf('Too many ItemOut (%d at most)', $this->maxItemsOut));
        }
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
