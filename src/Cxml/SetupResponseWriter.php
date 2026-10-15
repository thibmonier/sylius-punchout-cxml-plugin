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
    use CxmlDocumentTrait;

    private const CXML_VERSION = '1.2.071';

    public function __construct(private PayloadIdGenerator $payloadIdGenerator)
    {
    }

    public function write(string $startUrl, string $hostname = 'localhost'): string
    {
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
    public function writeError(int $code, string $text, string $hostname = 'localhost'): string
    {
        return $this->writeStatus($code, $text, $text, $hostname);
    }

    /** Writes a response carrying only a Status (OrderRequest answers, errors). */
    public function writeStatus(int $code, string $text, string $message = '', string $hostname = 'localhost'): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $cxml = $doc->createElement('cXML');
        $cxml->setAttribute('version', self::CXML_VERSION);
        $cxml->setAttribute('payloadID', $this->payloadIdGenerator->generate($hostname));
        $cxml->setAttribute('timestamp', $this->timestamp());
        $doc->appendChild($cxml);

        $response = $doc->createElement('Response');
        $cxml->appendChild($response);

        $status = $doc->createElement('Status');
        if ($message !== '') {
            $status->appendChild($doc->createTextNode($message));
        }
        $status->setAttribute('code', (string) $code);
        $status->setAttribute('text', $text);
        $response->appendChild($status);

        return $this->prependDoctype($doc->saveXML() ?: '');
    }
}
