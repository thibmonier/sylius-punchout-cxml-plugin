<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Cxml;

/** Shared parts of the cXML 1.2.071 documents written by the plugin. */
trait CxmlDocumentTrait
{
    /** ISO 8601 timestamp with an explicit offset (never "Z"). */
    private function timestamp(?\DateTimeImmutable $date = null): string
    {
        return ($date ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
    }

    /** Inserts the cXML 1.2.071 DOCTYPE after the XML declaration. */
    private function prependDoctype(string $xml): string
    {
        $newline = strpos($xml, "\n");
        if (!str_starts_with($xml, '<?xml') || $newline === false) {
            return $xml;
        }

        return substr($xml, 0, $newline)
            . "\n<!DOCTYPE cXML PUBLIC \"-//cXML.org//DTD cXML 1.2//EN\" \"http://xml.cxml.org/schemas/cXML/1.2.071/cXML.dtd\">"
            . substr($xml, $newline);
    }
}
