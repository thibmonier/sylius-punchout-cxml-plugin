<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Oci;

use Tmonier\SyliusPunchoutCxmlPlugin\Classification\ItemClassifier;
use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\TransferLine;

/**
 * The fields of the OCI 5.0 form posted back to SAP's HOOK_URL (US-020), see
 * project-management/analysis/F-oci-field-reference.md: one NEW_ITEM-* group per line, values cut to
 * the lengths of the OCI fields (multibyte safe), the full description as long text in 132 character
 * chunks, then the `~` fields SAP sent at the entry. A cancelled session sends the `~` fields only.
 */
final class OciFormWriter
{
    private const LONGTEXT_CHUNK = 132;

    /**
     * @param list<TransferLine> $lines
     * @param array<string, string> $context the OCI context of the session
     *
     * @return list<array{string, string}> name and value pairs (a long text repeats its name)
     */
    public function fields(array $lines, array $context): array
    {
        $fields = [];
        foreach ($lines as $index => $line) {
            $n = $index + 1;
            $item = static fn (string $field): string => sprintf('NEW_ITEM-%s[%d]', $field, $n);
            $fields[] = [$item('DESCRIPTION'), mb_substr($line->description, 0, 40)];
            $fields[] = [$item('QUANTITY'), (string) $line->quantity];
            $fields[] = [$item('UNIT'), mb_substr($line->unitOfMeasure, 0, 3)];
            $fields[] = [$item('PRICE'), $line->unitNetPrice];
            $fields[] = [$item('CURRENCY'), mb_substr($line->currency, 0, 5)];
            $fields[] = [$item('PRICEUNIT'), '1'];
            $fields[] = [$item('VENDORMAT'), mb_substr($line->variantCode, 0, 40)];
            $fields[] = [$item('EXT_PRODUCT_ID'), mb_substr($line->supplierPartAuxId, 0, 40)];
            // OCI needs no placeholder: an unclassified item has no category.
            if ($line->unspsc !== null && $line->unspsc !== ItemClassifier::UNCLASSIFIED) {
                $fields[] = [$item('EXT_SCHEMA_TYPE'), 'UNSPSC'];
                $fields[] = [$item('EXT_CATEGORY_ID'), $line->unspsc];
            }
            foreach (mb_str_split($line->description, self::LONGTEXT_CHUNK) as $chunk) {
                $fields[] = [sprintf('NEW_ITEM-LONGTEXT_%d:132[]', $n), $chunk];
            }
        }
        foreach ($context as $name => $value) {
            if (str_starts_with($name, '~')) {
                $fields[] = [$name, $value];
            }
        }

        return $fields;
    }

    /**
     * Parameters of the oci_transfer template.
     *
     * @param list<array{string, string}> $fields
     * @param array<string, string> $context
     *
     * @return array{fields: list<array{string, string}>, target: string, charset: string}
     */
    public function formParameters(array $fields, array $context): array
    {
        return ['fields' => $fields, 'target' => $this->target($context), 'charset' => $this->charset($context)];
    }

    /**
     * The fields as logged, one "name=value" line each.
     *
     * @param list<array{string, string}> $fields
     */
    public static function asText(array $fields): string
    {
        return implode("\n", array_map(static fn (array $field): string => $field[0] . '=' . $field[1], $fields));
    }

    /**
     * The frame the form is posted to: `returntarget`, else `~TARGET`, else `_top`.
     *
     * @param array<string, string> $context
     */
    public function target(array $context): string
    {
        return $context[OciContext::RETURN_TARGET] ?? $context['~TARGET'] ?? '_top';
    }

    /** @param array<string, string> $context */
    public function charset(array $context): string
    {
        return $context[OciContext::CHARSET] ?? 'UTF-8';
    }
}
