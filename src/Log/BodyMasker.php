<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Log;

use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\Exception\XmlLoadException;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\SafeXmlLoader;

/**
 * Masks what a logged cXML exchange must never show (US-024), on the parsed document — not on the
 * text, whose markup can vary (`<SharedSecret >`, attributes, entities):
 * - secrets and signatures are replaced by "***";
 * - Extrinsic elements outside the allowed list, contacts and addresses are removed (GDPR);
 * - e-mail addresses left in texts and attributes keep their first letter and domain only.
 * A body that cannot be parsed is never stored.
 */
final readonly class BodyMasker
{
    public const UNPARSEABLE = '[unparseable body]';

    private const SECRETS = ['SharedSecret', 'Password', 'CredentialMac', 'DigitalSignature'];

    private const PERSONAL_BLOCKS = ['Contact', 'ShipTo', 'BillTo', 'Address', 'PostalAddress', 'Phone', 'Fax', 'Email'];

    private const EMAIL = '/\b([\p{L}\p{N}])[\p{L}\p{N}._%+-]*@([\p{L}\p{N}.-]+\.\p{L}{2,})\b/u';

    /** @param list<string> $allowedExtrinsics */
    public function __construct(
        private SafeXmlLoader $xmlLoader,
        private array $allowedExtrinsics = [],
    ) {
    }

    public function mask(string $body): string
    {
        try {
            $document = $this->xmlLoader->load($body);
        } catch (XmlLoadException) {
            return self::UNPARSEABLE;
        }
        $xpath = new \DOMXPath($document);

        foreach (self::SECRETS as $name) {
            foreach ($this->elements($xpath, '//*[local-name()="' . $name . '"]') as $element) {
                $element->textContent = '***';
            }
        }
        foreach ($this->elements($xpath, '//*[local-name()="Extrinsic"]') as $extrinsic) {
            if (!in_array($extrinsic->getAttribute('name'), $this->allowedExtrinsics, true)) {
                $extrinsic->parentNode?->removeChild($extrinsic);
            }
        }
        foreach (self::PERSONAL_BLOCKS as $name) {
            foreach ($this->elements($xpath, '//*[local-name()="' . $name . '"]') as $element) {
                $element->parentNode?->removeChild($element);
            }
        }
        foreach ($xpath->query('//text() | //@*') ?: [] as $node) {
            if ($node instanceof \DOMText || $node instanceof \DOMAttr) {
                $node->nodeValue = (string) preg_replace(self::EMAIL, '$1***@$2', (string) $node->nodeValue);
            }
        }

        return (string) $document->saveXML();
    }

    /** @return list<\DOMElement> */
    private function elements(\DOMXPath $xpath, string $query): array
    {
        $elements = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }
}
