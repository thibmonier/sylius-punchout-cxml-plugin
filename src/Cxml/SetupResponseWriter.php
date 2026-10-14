<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cxml;

/**
 * Writes a cXML 1.2.071 PunchOutSetupResponse document.
 *
 * - DOCTYPE SYSTEM identifier: cXML 1.2.071
 * - payloadID: unique (timestamp.random@hostname)
 * - timestamp: ISO 8601 with UTC offset, never "Z"
 */
final readonly class SetupResponseWriter
{
    private const DOCTYPE_PUBLIC = '-//cXML.org//DTD cXML 1.2//EN';

    private const DOCTYPE_SYSTEM = 'http://xml.cxml.org/schemas/cXML/1.2.071/cXML.dtd';

    private const CXML_VERSION = '1.2.071';

    public function __construct(private PayloadIdGenerator $payloadIdGenerator)
    {
    }

    public function write(
        string $startUrl,
        string $buyerPayloadId,
        string $supplierDomain,
        string $supplierIdentity,
        string $hostname = 'localhost',
    ): string {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        // cXML root
        $cxml = $doc->createElement('cXML');
        $cxml->setAttribute('version', self::CXML_VERSION);
        $cxml->setAttribute('payloadID', $this->payloadIdGenerator->generate($hostname));
        $cxml->setAttribute('timestamp', $this->timestamp());
        $doc->appendChild($cxml);

        // Response/Status
        $response = $doc->createElement('Response');
        $cxml->appendChild($response);

        $status = $doc->createElement('Status');
        $status->setAttribute('code', '200');
        $status->setAttribute('text', 'OK');
        $response->appendChild($status);

        // PunchOutSetupResponse
        $setupResponse = $doc->createElement('PunchOutSetupResponse');
        $response->appendChild($setupResponse);

        $startPage = $doc->createElement('StartPage');
        $setupResponse->appendChild($startPage);

        $urlEl = $doc->createElement('URL', $startUrl);
        $startPage->appendChild($urlEl);

        // Prepend DOCTYPE
        return $this->prependDoctype($doc->saveXML() ?: '');
    }

    /**
     * Writes an error response with the given status code and text.
     */
    public function writeError(
        int $code,
        string $text,
        string $buyerPayloadId = '',
        string $hostname = 'localhost',
    ): string {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $cxml = $doc->createElement('cXML');
        $cxml->setAttribute('version', self::CXML_VERSION);
        $cxml->setAttribute('payloadID', $this->payloadIdGenerator->generate($hostname));
        $cxml->setAttribute('timestamp', $this->timestamp());
        $doc->appendChild($cxml);

        $response = $doc->createElement('Response');
        $cxml->appendChild($response);

        $status = $doc->createElement('Status', htmlspecialchars($text));
        $status->setAttribute('code', (string) $code);
        $status->setAttribute('text', $text);
        $response->appendChild($status);

        return $this->prependDoctype($doc->saveXML() ?: '');
    }

    /**
     * Returns an ISO 8601 timestamp with explicit UTC offset (never "Z").
     */
    private function timestamp(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:sP');
    }

    private function prependDoctype(string $xml): string
    {
        // Remove the <?xml ..> declaration line, insert DOCTYPE after it.
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
