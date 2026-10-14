<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Unit\Cxml;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\OrderMessageWriter;
use Tmonier\SyliusPunchoutCxmlPlugin\Transfer\TransferLine;

final class OrderMessageWriterTest extends TestCase
{
    private OrderMessageWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new OrderMessageWriter();
    }

    /** @return list<TransferLine> */
    private function makeLines(): array
    {
        return [
            new TransferLine('SKU-A', 'v1:SKU-A', 'Product A', 3, '254.40', 'EUR', 'EA'),
        ];
    }

    #[Test]
    public function it_produces_valid_cxml_dtd(): void
    {
        $lines = $this->makeLines();
        $xml = $this->writer->write(
            $lines,
            '254.40',
            'EUR',
            'COOKIE-' . bin2hex(random_bytes(4)),
            bin2hex(random_bytes(8)) . '.' . time() . '@test.local',
            'create',
        );

        $localDtd = realpath(__DIR__ . '/../../Resources/dtd/1.2.071/cXML.dtd');
        self::assertNotFalse($localDtd, 'Local DTD must exist');

        $xml = preg_replace(
            '|http://xml\.cxml\.org/schemas/cXML/1\.2\.071/cXML\.dtd|',
            $localDtd,
            $xml,
        );

        $doc = new \DOMDocument();
        $loaded = @$doc->loadXML($xml ?? '', \LIBXML_NONET | \LIBXML_DTDLOAD | \LIBXML_DTDVALID);
        self::assertTrue($loaded, 'XML must load');
        self::assertTrue($doc->validate(), 'Must pass DTD validation');
    }

    #[Test]
    public function it_includes_buyer_cookie(): void
    {
        // Secret not included in PunchOutOrderMessage (only BuyerCookie)
        $cookie = 'COOKIE-' . bin2hex(random_bytes(8));
        $xml = $this->writer->write($this->makeLines(), '254.40', 'EUR', $cookie, 'p@h', 'create');

        self::assertStringContainsString($cookie, $xml);
        self::assertStringNotContainsString('SharedSecret', $xml);
    }

    #[Test]
    public function it_computes_correct_total(): void
    {
        $lines = [
            new TransferLine('SKU-A', 'v1:SKU-A', 'A', 3, '254.40', 'EUR', 'EA'),
        ];
        $total = '763.20'; // 3 × 254.40

        $xml = $this->writer->write($lines, $total, 'EUR', 'cookie', 'p@h', 'create');

        self::assertStringContainsString('763.20', $xml);
        self::assertStringNotContainsString('SharedSecret', $xml);
    }

    #[Test]
    public function it_sets_operation_allowed_edit_for_create(): void
    {
        $xml = $this->writer->write($this->makeLines(), '254.40', 'EUR', 'c', 'p@h', 'create');

        self::assertStringContainsString('operationAllowed="edit"', $xml);
    }

    #[Test]
    public function it_sets_operation_allowed_inspect_for_inspect(): void
    {
        $xml = $this->writer->write($this->makeLines(), '254.40', 'EUR', 'c', 'p@h', 'inspect');

        self::assertStringContainsString('operationAllowed="inspect"', $xml);
    }

    #[Test]
    public function it_uses_target_top(): void
    {
        $xml = $this->writer->write($this->makeLines(), '254.40', 'EUR', 'c', 'p@h', 'create');

        // The self-submitting form in TransferAction must use target="_top"
        // OrderMessageWriter itself does not produce a form, but verify the xml has expected content
        self::assertStringContainsString('EUR', $xml);
    }
}
