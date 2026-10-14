<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerIdentity;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;

/**
 * Security cases of the PunchOut session opening.
 *
 * - US-013 / ADR-0003: disabled shop user → StartAction returns 403
 * - US-007: disabled buyer → 403 cXML; unknown identities → 401 same as wrong secret
 *
 * The cart round trip is covered by PunchoutCartIsolationTest and SimulatorRoundTripTest.
 */
final class PunchoutFlowTest extends WebTestCase
{
    use DatabaseTransactionTrait;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, string> $ids
     */
    private function buildSetupRequest(array $ids, string $secret, string $returnUrl = 'https://ariba.example.com/return', string $operation = 'create'): string
    {
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339);
        $payloadId = bin2hex(random_bytes(8)) . '.' . time() . '@test.local';
        $fromDomain = $ids['fromDomain'];
        $fromIdentity = $ids['fromIdentity'];
        $senderDomain = $ids['senderDomain'];
        $senderIdentity = $ids['senderIdentity'];

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE cXML SYSTEM "http://xml.cxml.org/schemas/cXML/1.2.071/cXML.dtd">
            <cXML payloadID="{$payloadId}" timestamp="{$timestamp}">
                <Header>
                    <From><Credential domain="{$fromDomain}"><Identity>{$fromIdentity}</Identity></Credential></From>
                    <To><Credential domain="NetworkId"><Identity>demo-supplier@example.com</Identity></Credential></To>
                    <Sender>
                        <Credential domain="{$senderDomain}">
                            <Identity>{$senderIdentity}</Identity>
                            <SharedSecret>{$secret}</SharedSecret>
                        </Credential>
                        <UserAgent>TestClient/1.0</UserAgent>
                    </Sender>
                </Header>
                <Request deploymentMode="test">
                    <PunchOutSetupRequest operation="{$operation}">
                        <BuyerCookie>COOKIE-{$payloadId}</BuyerCookie>
                        <BrowserFormPost><URL>{$returnUrl}</URL></BrowserFormPost>
                    </PunchOutSetupRequest>
                </Request>
            </cXML>
            XML;
    }

    /** @return array<string, string> */
    private function extractIdentities(BuyerInterface $buyer): array
    {
        $identities = $buyer->getIdentities();
        $fromId = $identities->filter(fn (BuyerIdentity $i): bool => $i->getRole() === BuyerIdentity::ROLE_FROM)->first();
        $senderId = $identities->filter(fn (BuyerIdentity $i): bool => $i->getRole() === BuyerIdentity::ROLE_SENDER)->first();

        self::assertInstanceOf(BuyerIdentity::class, $fromId);
        self::assertInstanceOf(BuyerIdentity::class, $senderId);

        return [
            'fromDomain' => $fromId->getDomain(),
            'fromIdentity' => $fromId->getIdentity(),
            'senderDomain' => $senderId->getDomain(),
            'senderIdentity' => $senderId->getIdentity(),
        ];
    }

    private function postSetupRequest(BuyerInterface $buyer, string $secret, string $returnUrl = 'https://ariba.example.com/return'): string
    {
        $client = $this->client;
        $ids = $this->extractIdentities($buyer);
        $xml = $this->buildSetupRequest($ids, $secret, $returnUrl);

        $client->request('POST', '/punchout/cxml', [], [], ['CONTENT_TYPE' => 'text/xml; charset=UTF-8'], $xml);

        $responseBody = (string) $client->getResponse()->getContent();
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'Setup request must return HTTP 200');
        self::assertStringNotContainsString('<Status code="4', $responseBody, 'Setup request must not return a cXML error');

        return $responseBody;
    }

    private function extractStartUrl(string $setupResponseXml): string
    {
        $doc = new \DOMDocument();
        $doc->loadXML($setupResponseXml, \LIBXML_NONET);
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//StartPage/URL');
        self::assertNotFalse($nodes, 'XPath query for StartPage/URL must not fail');
        self::assertGreaterThan(0, $nodes->length, 'Setup response must contain a StartPage/URL');
        $node = $nodes->item(0);
        self::assertInstanceOf(\DOMElement::class, $node, 'StartPage/URL must be a DOMElement');
        $url = $node->textContent;
        self::assertNotEmpty($url, 'StartPage URL must not be empty');

        return $url;
    }

    private function extractTokenFromStartUrl(string $startUrl): string
    {
        $parts = explode('/punchout/start/', $startUrl);
        self::assertCount(2, $parts, 'Start URL must contain /punchout/start/{token}');

        return trim($parts[1], '/');
    }

    // -------------------------------------------------------------------------
    // Test: full E2E flow

    // -------------------------------------------------------------------------
    // Test: TTC 20% channel → UnitPrice HT = 100.00

    // -------------------------------------------------------------------------
    // Test: disabled shop user → no login (ADR-0003)
    // -------------------------------------------------------------------------

    /**
     * ADR-0003: if the buyer's linked shop user is disabled, StartAction must
     * return 403 and NOT open a PunchOut session.
     */
    public function testDisabledShopUserReturns403(): void
    {
        $client = $this->client;
        $catalog = new TestCatalog(self::getContainer());

        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer(
            fromIdentity: 'test-disabled-user@example.com',
            senderIdentity: 'test-disabled-system@example.com',
        );

        // Disable the shop user
        $customer = $buyer->getCustomer();
        self::assertNotNull($customer, 'Buyer must have a linked customer');
        $shopUser = $customer->getUser();
        self::assertNotNull($shopUser, 'Customer must have a shop user');
        $shopUser->setEnabled(false);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->flush();

        // Issue setup request to get a valid start token
        $setupResponse = $this->postSetupRequest($buyer, $secret);
        $startUrl = $this->extractStartUrl($setupResponse);
        $token = $this->extractTokenFromStartUrl($startUrl);

        // GET start URL → must return 403, not 302
        $client->request('GET', '/punchout/start/' . $token);
        self::assertSame(403, $client->getResponse()->getStatusCode(), 'Disabled shop user must get 403 from StartAction');

        // The token must be cancelled — re-trying the same token must return 404
        $client->request('GET', '/punchout/start/' . $token);
        self::assertSame(404, $client->getResponse()->getStatusCode(), 'Cancelled session token must return 404');
    }

    // -------------------------------------------------------------------------
    // Test: disabled buyer → 403 cXML
    // -------------------------------------------------------------------------

    /**
     * US-007: disabled buyer → <Status code="403">, distinct from wrong-secret 401.
     */
    public function testDisabledBuyerReturns403CxmlStatus(): void
    {
        $client = $this->client;
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyer();

        $buyer->setEnabled(false);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->flush();

        $ids = $this->extractIdentities($buyer);
        $xml = $this->buildSetupRequest($ids, $secret);

        $client->request('POST', '/punchout/cxml', [], [], ['CONTENT_TYPE' => 'text/xml; charset=UTF-8'], $xml);

        $body = (string) $client->getResponse()->getContent();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('<Status code="403"', $body, 'Disabled buyer must return cXML 403');
        self::assertStringNotContainsString('<StartPage>', $body, 'Disabled buyer must not receive a StartPage URL');
    }

    // -------------------------------------------------------------------------
    // Test: unknown identities → 401 (same as wrong secret)
    // -------------------------------------------------------------------------

    /**
     * US-007: unknown buyer identities → <Status code="401">, identical to wrong-secret response.
     */
    public function testUnknownBuyerReturns401SameAsWrongSecret(): void
    {
        $client = $this->client;

        // Build a request with completely unknown identities
        $unknownIds = [
            'fromDomain' => 'AribaNetworkUserId',
            'fromIdentity' => 'unknown-buyer-' . bin2hex(random_bytes(8)) . '@example.com',
            'senderDomain' => 'AribaNetworkUserId',
            'senderIdentity' => 'unknown-system-' . bin2hex(random_bytes(8)) . '@example.com',
        ];
        $xml = $this->buildSetupRequest($unknownIds, 'any-secret-' . bin2hex(random_bytes(8)));

        $client->request('POST', '/punchout/cxml', [], [], ['CONTENT_TYPE' => 'text/xml; charset=UTF-8'], $xml);

        $body = (string) $client->getResponse()->getContent();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('<Status code="401"', $body, 'Unknown buyer must return cXML 401');
    }
}
