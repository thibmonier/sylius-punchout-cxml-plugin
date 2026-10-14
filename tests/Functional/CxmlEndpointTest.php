<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerIdentity;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;

final class CxmlEndpointTest extends WebTestCase
{
    use DatabaseTransactionTrait;

    private function buildSetupRequest(
        string $fromDomain,
        string $fromIdentity,
        string $senderDomain,
        string $senderIdentity,
        string $sharedSecret,
        string $supplierDomain = 'NetworkId',
        string $supplierIdentity = 'demo-supplier@example.com',
        string $returnUrl = 'https://ariba.example.com/return',
        string $operation = 'create',
    ): string {
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339);
        $payloadId = bin2hex(random_bytes(8)) . '.' . time() . '@test.local';

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE cXML SYSTEM "http://xml.cxml.org/schemas/cXML/1.2.071/cXML.dtd">
            <cXML payloadID="{$payloadId}" timestamp="{$timestamp}">
                <Header>
                    <From><Credential domain="{$fromDomain}"><Identity>{$fromIdentity}</Identity></Credential></From>
                    <To><Credential domain="{$supplierDomain}"><Identity>{$supplierIdentity}</Identity></Credential></To>
                    <Sender>
                        <Credential domain="{$senderDomain}">
                            <Identity>{$senderIdentity}</Identity>
                            <SharedSecret>{$sharedSecret}</SharedSecret>
                        </Credential>
                        <UserAgent>TestClient/1.0</UserAgent>
                    </Sender>
                </Header>
                <Request deploymentMode="test">
                    <PunchOutSetupRequest operation="{$operation}">
                        <BuyerCookie>TEST-{$payloadId}</BuyerCookie>
                        <BrowserFormPost><URL>{$returnUrl}</URL></BrowserFormPost>
                    </PunchOutSetupRequest>
                </Request>
            </cXML>
            XML;
    }

    /** @return array{fromDomain: string, fromIdentity: string, senderDomain: string, senderIdentity: string} */
    private function extractIdentities(BuyerInterface $buyer): array
    {
        $identities = $buyer->getIdentities();
        $fromId = $identities->filter(fn (BuyerIdentity $i): bool => $i->getRole() === BuyerIdentity::ROLE_FROM)->first();
        $senderId = $identities->filter(fn (BuyerIdentity $i): bool => $i->getRole() === BuyerIdentity::ROLE_SENDER)->first();

        self::assertInstanceOf(BuyerIdentity::class, $fromId, 'Buyer must have a FROM identity');
        self::assertInstanceOf(BuyerIdentity::class, $senderId, 'Buyer must have a SENDER identity');

        return [
            'fromDomain' => $fromId->getDomain(),
            'fromIdentity' => $fromId->getIdentity(),
            'senderDomain' => $senderId->getDomain(),
            'senderIdentity' => $senderId->getIdentity(),
        ];
    }

    public function testSetupRequestReturns200WithStartUrl(): void
    {
        $client = $this->client;
        $catalog = new TestCatalog(static::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyer();

        $ids = $this->extractIdentities($buyer);
        $xml = $this->buildSetupRequest(
            $ids['fromDomain'],
            $ids['fromIdentity'],
            $ids['senderDomain'],
            $ids['senderIdentity'],
            $secret,
        );

        $client->request('POST', '/punchout/cxml', [], [], [
            'CONTENT_TYPE' => 'text/xml; charset=UTF-8',
        ], $xml);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/xml', $response->headers->get('Content-Type') ?? '');
        // Symfony may append 'private' automatically; we only require 'no-store' to be present
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control') ?? '');
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertStringContainsString('<StartPage>', (string) $response->getContent());
        self::assertStringContainsString('/punchout/start/', (string) $response->getContent());
    }

    public function testSetupRequestReturns401ForBadSecret(): void
    {
        $client = $this->client;
        $catalog = new TestCatalog(static::getContainer());
        ['buyer' => $buyer] = $catalog->createBuyer();

        $ids = $this->extractIdentities($buyer);
        $xml = $this->buildSetupRequest(
            $ids['fromDomain'],
            $ids['fromIdentity'],
            $ids['senderDomain'],
            $ids['senderIdentity'],
            'wrong-secret-' . bin2hex(random_bytes(8)), // wrong secret, generated at runtime
        );

        $client->request('POST', '/punchout/cxml', [], [], [
            'CONTENT_TYPE' => 'text/xml; charset=UTF-8',
        ], $xml);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode()); // cXML always returns 200 HTTP
        self::assertStringContainsString('<Status code="401"', (string) $response->getContent());
    }

    public function testSetupRequestRejectHttpReturnUrl(): void
    {
        $client = $this->client;
        $catalog = new TestCatalog(static::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyer();

        $ids = $this->extractIdentities($buyer);
        $xml = $this->buildSetupRequest(
            $ids['fromDomain'],
            $ids['fromIdentity'],
            $ids['senderDomain'],
            $ids['senderIdentity'],
            $secret,
            returnUrl: 'http://ariba.example.com/return', // HTTP not HTTPS
        );

        $client->request('POST', '/punchout/cxml', [], [], [
            'CONTENT_TYPE' => 'text/xml; charset=UTF-8',
        ], $xml);

        self::assertStringContainsString(
            '<Status code="403"',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testEndpointRejectsXxePayload(): void
    {
        $client = $this->client;
        $xxe = <<<'XML_WRAP'
        <?xml version="1.0"?>
        <!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
        <cXML><Request>&xxe;</Request></cXML>
        XML_WRAP;

        $client->request('POST', '/punchout/cxml', [], [], [
            'CONTENT_TYPE' => 'text/xml; charset=UTF-8',
        ], $xxe);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<Status code="406"', (string) $response->getContent());
    }

    /**
     * US-007 security: a buyer with enabled=false must receive a 403 cXML response.
     * The identity is known (correct secret) but access is forbidden.
     * This is distinct from the unknown-buyer / wrong-secret 401.
     */
    public function testDisabledBuyerReturns403(): void
    {
        $client = $this->client;
        $catalog = new TestCatalog(static::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyer();

        // Disable the buyer after creation
        $buyer->setEnabled(false);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->flush();

        $ids = $this->extractIdentities($buyer);
        $xml = $this->buildSetupRequest(
            $ids['fromDomain'],
            $ids['fromIdentity'],
            $ids['senderDomain'],
            $ids['senderIdentity'],
            $secret,
        );

        $client->request('POST', '/punchout/cxml', [], [], [
            'CONTENT_TYPE' => 'text/xml; charset=UTF-8',
        ], $xml);

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode()); // cXML always returns HTTP 200
        // Must return 403 Forbidden (identity known, access denied)
        self::assertStringContainsString('<Status code="403"', (string) $response->getContent());
        // Must not return a StartPage URL
        self::assertStringNotContainsString('<StartPage>', (string) $response->getContent());
    }

    /**
     * US-007 security: no Set-Cookie header must ever appear on the cXML endpoint
     * (cXML systems are not browser sessions).
     */
    public function testEndpointNeverSetsSessionCookie(): void
    {
        $client = $this->client;
        $catalog = new TestCatalog(static::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyer();

        $ids = $this->extractIdentities($buyer);
        $xml = $this->buildSetupRequest(
            $ids['fromDomain'],
            $ids['fromIdentity'],
            $ids['senderDomain'],
            $ids['senderIdentity'],
            $secret,
        );

        $client->request('POST', '/punchout/cxml', [], [], [
            'CONTENT_TYPE' => 'text/xml; charset=UTF-8',
        ], $xml);

        self::assertFalse(
            $client->getResponse()->headers->has('Set-Cookie'),
            'The cXML endpoint must never set a cookie',
        );
    }
}
