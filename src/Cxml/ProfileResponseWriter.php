<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cxml;

/**
 * Writes the cXML ProfileResponse (US-010): the transactions this supplier accepts, all on the
 * same endpoint, and the PunchOut operations it supports.
 */
final readonly class ProfileResponseWriter
{
    use CxmlDocumentTrait;

    /** When the PunchOut service became available (first release of the plugin). */
    private const EFFECTIVE_DATE = '2026-10-14T00:00:00+00:00';

    public function __construct(private PayloadIdGenerator $payloadIdGenerator)
    {
    }

    public function write(string $endpointUrl, string $hostname = 'localhost'): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $cxml = $doc->createElement('cXML');
        $cxml->setAttribute('version', '1.2.071');
        $cxml->setAttribute('payloadID', $this->payloadIdGenerator->generate($hostname));
        $cxml->setAttribute('timestamp', $this->timestamp());
        $doc->appendChild($cxml);

        $response = $cxml->appendChild($doc->createElement('Response'));
        $status = $response->appendChild($doc->createElement('Status'));
        \assert($status instanceof \DOMElement);
        $status->setAttribute('code', '200');
        $status->setAttribute('text', 'OK');

        $profile = $response->appendChild($doc->createElement('ProfileResponse'));
        \assert($profile instanceof \DOMElement);
        $profile->setAttribute('effectiveDate', self::EFFECTIVE_DATE);

        $profile->appendChild($this->transaction($doc, 'ProfileRequest', $endpointUrl));
        $setup = $profile->appendChild($this->transaction($doc, 'PunchOutSetupRequest', $endpointUrl));
        $option = $setup->appendChild($doc->createElement('Option'));
        \assert($option instanceof \DOMElement);
        $option->setAttribute('name', 'operationAllowed');
        $option->appendChild($doc->createTextNode('create edit inspect'));

        return $this->prependDoctype($doc->saveXML() ?: '');
    }

    private function transaction(\DOMDocument $doc, string $requestName, string $url): \DOMElement
    {
        $transaction = $doc->createElement('Transaction');
        $transaction->setAttribute('requestName', $requestName);
        $urlElement = $transaction->appendChild($doc->createElement('URL'));
        $urlElement->appendChild($doc->createTextNode($url));

        return $transaction;
    }
}
