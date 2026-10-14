<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Unit\Oci;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusPunchoutCxmlPlugin\Oci\OciFormWriter;
use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\TransferLine;

final class OciFormWriterTest extends TestCase
{
    public function testAClassifiedItemCarriesItsUnspscCategory(): void
    {
        $fields = (new OciFormWriter())->fields([$this->line('Paper', unspsc: '14111507')], []);

        self::assertContains(['NEW_ITEM-EXT_SCHEMA_TYPE[1]', 'UNSPSC'], $fields);
        self::assertContains(['NEW_ITEM-EXT_CATEGORY_ID[1]', '14111507'], $fields);
    }

    public function testValuesAreCutToTheOciLengthsWithoutBreakingCharacters(): void
    {
        $description = str_repeat('é', 300);
        $fields = (new OciFormWriter())->fields([$this->line($description, unit: 'BOX', code: str_repeat('C', 60))], []);

        self::assertContains(['NEW_ITEM-DESCRIPTION[1]', str_repeat('é', 40)], $fields);
        self::assertContains(['NEW_ITEM-UNIT[1]', 'BOX'], $fields);
        self::assertContains(['NEW_ITEM-VENDORMAT[1]', str_repeat('C', 40)], $fields);
        $longText = array_values(array_filter($fields, static fn (array $field): bool => $field[0] === 'NEW_ITEM-LONGTEXT_1:132[]'));
        self::assertSame([132, 132, 36], array_map(static fn (array $field): int => mb_strlen($field[1]), $longText));
        self::assertSame($description, implode('', array_column($longText, 1)));
    }

    public function testLinesAreNumberedFromOne(): void
    {
        $fields = (new OciFormWriter())->fields([$this->line('A'), $this->line('B')], ['~OkCode' => 'ADDI']);

        self::assertContains(['NEW_ITEM-DESCRIPTION[2]', 'B'], $fields);
        self::assertSame(['~OkCode', 'ADDI'], end($fields));
    }

    public function testTheTargetIsTheReturnTargetThenTildeTargetThenTop(): void
    {
        $writer = new OciFormWriter();

        self::assertSame('_parent', $writer->target(['returntarget' => '_parent', '~TARGET' => 'main']));
        self::assertSame('main', $writer->target(['~TARGET' => 'main']));
        self::assertSame('_top', $writer->target([]));
    }

    private function line(string $description, string $unit = 'EA', string $code = 'V1', ?string $unspsc = null): TransferLine
    {
        return new TransferLine($code, 'v1:' . $code, $description, 1, '10.00', 'EUR', $unit, $unspsc);
    }
}
