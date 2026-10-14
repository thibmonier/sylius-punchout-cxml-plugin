<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Unit\Cxml;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\PayloadIdGenerator;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\SetupResponseWriter;

final class SetupResponseWriterTest extends TestCase
{
    private SetupResponseWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new SetupResponseWriter(new PayloadIdGenerator());
    }

    #[Test]
    public function it_produces_valid_cxml_dtd(): void
    {
        $xml = $this->writer->write(
            'https://shop.example.com/punchout/start/test-token',
            '20261014.abc@buyer.example.com',
            'NetworkId',
            'supplier',
        );

        $doc = new \DOMDocument();
        // Replace the SYSTEM identifier with the local DTD path for validation
        $localDtd = realpath(__DIR__ . '/../../Resources/dtd/1.2.071/cXML.dtd');
        self::assertNotFalse($localDtd, 'Local DTD file must exist');

        $xml = preg_replace(
            '|http://xml\.cxml\.org/schemas/cXML/1\.2\.071/cXML\.dtd|',
            $localDtd,
            $xml,
        );

        $loaded = @$doc->loadXML($xml ?? '', \LIBXML_NONET | \LIBXML_DTDLOAD | \LIBXML_DTDVALID);
        self::assertTrue($loaded, 'The XML must load without parse errors');
        self::assertTrue($doc->validate(), 'The document must pass DTD validation');
    }

    #[Test]
    public function it_includes_start_url(): void
    {
        $startUrl = 'https://shop.example.com/punchout/start/abc123';
        $xml = $this->writer->write($startUrl, 'payload@host', 'NetworkId', 'supplier');

        self::assertStringContainsString($startUrl, $xml);
    }

    #[Test]
    public function it_generates_unique_payload_ids(): void
    {
        $xml1 = $this->writer->write('https://a.example.com/s', 'p1@h', 'N', 's');
        $xml2 = $this->writer->write('https://a.example.com/s', 'p2@h', 'N', 's');

        preg_match('/payloadID="([^"]+)"/', $xml1, $m1);
        preg_match('/payloadID="([^"]+)"/', $xml2, $m2);

        self::assertNotSame($m1[1] ?? '', $m2[1] ?? '');
    }

    #[Test]
    public function it_uses_iso8601_offset_not_z(): void
    {
        $xml = $this->writer->write('https://a.example.com/s', 'p@h', 'N', 's');

        preg_match('/timestamp="([^"]+)"/', $xml, $m);
        $timestamp = $m[1] ?? '';

        self::assertNotEmpty($timestamp);
        // Must contain +HH:MM or -HH:MM, NOT "Z"
        self::assertMatchesRegularExpression(
            '/[+-]\d{2}:\d{2}$/',
            $timestamp,
            "Timestamp must end with offset like +00:00, got: {$timestamp}",
        );
        self::assertStringNotContainsString(
            'Z"',
            $xml,
            'Timestamp must not use Z notation',
        );
    }

    #[Test]
    public function it_writes_error_response_with_correct_status_code(): void
    {
        $xml = $this->writer->writeError(401, 'Unauthorized');

        $doc = new \DOMDocument();
        $doc->loadXML($xml, \LIBXML_NONET);
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//Status');
        self::assertNotFalse($nodes);
        $status = $nodes->item(0);
        self::assertInstanceOf(\DOMElement::class, $status);
        self::assertSame('401', $status->getAttribute('code'));
    }
}
