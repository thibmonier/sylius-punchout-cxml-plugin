<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cxml;

use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\Exception\XmlLoadException;

/**
 * Loads XML documents defensively:
 * - Enforces a byte-size limit.
 * - Rejects any DOCTYPE with an internal subset (<!ENTITY, [ … ]) to prevent XXE.
 * - Uses LIBXML_NONET only — never LIBXML_NOENT, DTDLOAD, DTDVALID or PARSEHUGE.
 * - Captures libxml errors and always restores the previous error handler.
 */
final readonly class SafeXmlLoader
{
    private const DEFAULT_MAX_BYTES = 262144; // 256 KiB

    public function __construct(private int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
    }

    public function load(string $xml): \DOMDocument
    {
        if (\strlen($xml) > $this->maxBytes) {
            throw new XmlLoadException(
                sprintf('XML document exceeds the %d-byte limit', $this->maxBytes),
            );
        }

        if ($this->hasInternalSubset($xml)) {
            throw new XmlLoadException(
                'XML document contains an internal DOCTYPE subset; loading refused (potential XXE)',
            );
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $doc = new \DOMDocument();
            // LIBXML_NONET prevents network access during parsing.
            // No LIBXML_NOENT (entity expansion), DTDLOAD, DTDVALID, or PARSEHUGE.
            $result = $doc->loadXML($xml, \LIBXML_NONET);
            if (!$result) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                $first = $errors[0] ?? null;

                throw new XmlLoadException(
                    sprintf('XML parse error: %s', $first instanceof \LibXMLError ? trim($first->message) : 'unknown'),
                );
            }

            return $doc;
        } finally {
            libxml_use_internal_errors($previous);
            libxml_clear_errors();
        }
    }

    /**
     * Returns true if the XML string contains an internal DOCTYPE subset or any
     * ENTITY declaration, which could be used for XXE / entity expansion attacks.
     */
    private function hasInternalSubset(string $xml): bool
    {
        // Fast path: any <!ENTITY declaration is rejected regardless of location.
        if (str_contains($xml, '<!ENTITY')) {
            return true;
        }

        $pos = stripos($xml, '<!DOCTYPE');
        if ($pos === false) {
            return false;
        }

        // Look for an internal subset marker '[' before the closing '>'.
        $afterDoctype = substr($xml, $pos);
        $gtPos = strpos($afterDoctype, '>');
        if ($gtPos === false) {
            return false;
        }

        return str_contains(substr($afterDoctype, 0, $gtPos), '[');
    }
}
