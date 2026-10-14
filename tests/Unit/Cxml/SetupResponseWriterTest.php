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
    public function it_produces_valid_cxml_structure(): void
    {
        $xml = $this->writer->write(
            'https://shop.example.com/punchout/start/test-token',
            '20261014.abc@buyer.example.com',
            'NetworkId',
            'supplier',
        );

        // Verify well-formed XML and expected structure via XPath
        $doc = new \DOMDocument();
        $loaded = $doc->loadXML($xml, \LIBXML_NONET);
        self::assertTrue($loaded, 'The XML must load without parse errors');

        $xpath = new \DOMXPath($doc);

        $statusNodes = $xpath->query('//Response/Status[@code="200"]');
        self::assertNotFalse($statusNodes);
        self::assertGreaterThan(0, $statusNodes->length, 'Must contain a Status 200 element');

        $startPageNodes = $xpath->query('//PunchOutSetupResponse/StartPage/URL');
        self::assertNotFalse($startPageNodes);
        self::assertGreaterThan(0, $startPageNodes->length, 'Must contain a StartPage URL element');
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

    #[Test]
    public function it_does_not_double_escape_ampersand_in_error_text(): void
    {
        $xml = $this->writer->writeError(401, 'Error & test');

        // The serialized XML must contain &amp; (the DOM serializes & as &amp;)
        self::assertStringContainsString('Error &amp; test', $xml);
        // But NOT &amp;amp; (which would be double-escaping)
        self::assertStringNotContainsString('&amp;amp;', $xml);
    }

    #[Test]
    public function it_uses_buyer_payload_id_as_in_reply_to(): void
    {
        $buyerPayloadId = 'test-payload-12345@buyer.com';
        $xml = $this->writer->write(
            'https://shop.example.com/s',
            $buyerPayloadId,
            'NetworkId',
            'supplier',
        );

        self::assertStringContainsString('inReplyTo="' . $buyerPayloadId . '"', $xml);
    }
}
