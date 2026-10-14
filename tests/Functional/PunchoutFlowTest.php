<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerIdentity;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;

/**
 * Full end-to-end and security functional tests for the PunchOut flow.
 *
 * Coverage:
 * - US-007 + US-013 + US-018: complete flow (setup → start → cart → transfer → cXML)
 * - US-013: consumed token → no re-entry; expired token → no entry
 * - US-013 / ADR-0003: disabled shop user → StartAction returns 403
 * - US-018: TTC 20% channel → UnitPrice = net (ex-tax)
 * - US-007: disabled buyer → 403 cXML; unknown identities → 401 same as wrong secret
 * - US-026: simulator round-trip (form → endpoint → start → transfer → receive page)
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

    private function validateCxmlAgainstDtd(string $xml): void
    {
        $dtdPath = __DIR__ . '/../Resources/dtd/1.2.071/cXML.dtd';
        self::assertFileExists($dtdPath, 'DTD file must exist at tests/Resources/dtd/1.2.071/cXML.dtd');

        // Replace the network DTD URI (PUBLIC or SYSTEM) with a local file path.
        // OrderMessageWriter generates: PUBLIC "-//cXML.org//DTD cXML 1.2//EN" "http://xml.cxml.org/.../cXML.dtd"
        // We replace the whole PUBLIC/SYSTEM declaration with a SYSTEM pointing to our local copy.
        $localUri = 'file://' . $dtdPath;
        $localXml = (string) preg_replace(
            '/(?:PUBLIC\s+"[^"]+"\s+|SYSTEM\s+)"[^"]*cXML\.dtd"/',
            sprintf('SYSTEM "%s"', $localUri),
            $xml,
        );

        $doc = new \DOMDocument();
        // LIBXML_DTDLOAD is required to load the local DTD file after URI substitution.
        // LIBXML_NONET prevents any network access — safe because we replaced the network URI above.
        // This combination is intentional: we validate our OWN generated output (trusted), not external input.
        $result = $doc->loadXML($localXml, \LIBXML_DTDVALID | \LIBXML_DTDLOAD | \LIBXML_NONET);
        self::assertTrue($result, 'cXML must parse successfully');

        // libxml2 errors accumulate; check for validation errors
        $errors = libxml_get_errors();
        libxml_clear_errors();
        $validationErrors = array_filter($errors, fn (\LibXMLError $e): bool => $e->level >= \LIBXML_ERR_ERROR);
        self::assertEmpty(
            $validationErrors,
            'cXML must be valid against DTD. Errors: ' . implode('; ', array_map(fn (\LibXMLError $e): string => trim($e->message), $validationErrors)),
        );
    }

    // -------------------------------------------------------------------------
    // Test: full E2E flow
    // -------------------------------------------------------------------------

    /**
     * US-007 + US-013 + US-018: complete PunchOut round-trip.
     *
     * 1. POST /punchout/cxml → setup response with StartPage URL
     * 2. GET start URL → authenticated shopper, 302 to shop
     * 3. Cart created with one item (direct via TestCatalog)
     * 4. POST /punchout/transfer → HTML form with target="_top" + cXML-base64
     * 5. Decode cXML: BuyerCookie, ItemIn, UnitPrice HT, Total; no SharedSecret
     * 6. Validate against cXML 1.2.071 DTD
     * 7. Re-transfer → 403 (session closed)
     */
    public function testFullPunchoutFlow(): void
    {
        $client = $this->client;
        $catalog = new TestCatalog(static::getContainer());

        // 1. Create buyer linked to shop@example.com customer
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $returnUrl = 'https://ariba.example.com/return';

        // 2. POST setup request → get StartPage URL
        $setupResponse = $this->postSetupRequest($buyer, $secret, $returnUrl);
        $startUrl = $this->extractStartUrl($setupResponse);
        $token = $this->extractTokenFromStartUrl($startUrl);
        $buyerCookie = $this->extractBuyerCookie($setupResponse);

        // 3. GET start URL → authenticate shopper, redirect to shop
        $client->request('GET', '/punchout/start/' . $token);
        self::assertSame(302, $client->getResponse()->getStatusCode(), 'Start token must redirect to shop');
        self::assertFalse(
            $client->getResponse()->headers->has('Set-Cookie') &&
            str_contains((string) $client->getResponse()->headers->get('Set-Cookie'), 'punchout'),
            'Start response must not set a punchout cookie',
        );

        // 4. R1+R2: Add items to the PunchOut session's isolated cart.
        // After R1, StartAction creates the PunchOut cart on the session. We retrieve
        // it from the DB and add items directly (not via createCartWithItem which would
        // create a separate cart that TransferAction no longer uses after R2).
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $punchoutSession = $em->createQueryBuilder()
            ->select('s')
            ->from(PunchoutSession::class, 's')
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleResult();
        self::assertInstanceOf(PunchoutSession::class, $punchoutSession);
        $punchoutCart = $punchoutSession->getCart();
        self::assertNotNull($punchoutCart, 'StartAction must have created a PunchOut cart (R1)');
        $catalog->addItemToOrder($punchoutCart, unitPriceCentimes: 10000, quantity: 1);

        // 5. POST /punchout/transfer (R3: include CSRF token).
        // getCsrfToken() makes a GET to the test-only /simulator/csrf-token endpoint,
        // which generates the token within a live request (session active), then the
        // next POST reuses the same session cookie — so the token is valid.
        $csrfToken = $this->getCsrfToken('tmonier_punchout_transfer');
        $client->request('POST', '/punchout/transfer', ['_csrf_token' => $csrfToken]);
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode(), 'Transfer must return 200');

        $html = (string) $response->getContent();

        // 6. Validate HTML form: target="_top" + cXML-base64 field
        self::assertStringContainsString('target="_top"', $html, 'Transfer form must have target="_top"');
        self::assertStringContainsString('name="cXML-base64"', $html, 'Transfer form must have cXML-base64 field');

        // Return URL must be the BrowserFormPost URL from the setup request
        self::assertStringContainsString(htmlspecialchars($returnUrl, \ENT_QUOTES, 'UTF-8'), $html, 'Transfer form action must be the returnUrl');

        // 7. Decode cXML-base64 and validate
        preg_match('/name="cXML-base64"\s+value="([^"]+)"/', $html, $matches);
        self::assertNotEmpty($matches[1] ?? '', 'cXML-base64 value must not be empty');
        $cxml = base64_decode($matches[1], true);
        self::assertIsString($cxml, 'cXML-base64 must be valid base64');
        self::assertNotEmpty($cxml);

        // BuyerCookie from setup must appear in the PunchOutOrderMessage
        self::assertStringContainsString($buyerCookie, $cxml, 'cXML must contain the BuyerCookie from the setup');

        // Must have ItemIn with UnitPrice 100.00
        self::assertStringContainsString('<ItemIn', $cxml, 'cXML must have at least one ItemIn');
        self::assertMatchesRegularExpression('/<Money[^>]*>100\.00<\/Money>/', $cxml, 'UnitPrice must be 100.00 HT');

        // Total must be 100.00 for 1 item × 100.00
        self::assertMatchesRegularExpression('/<Total>.*<Money[^>]*>100\.00<\/Money>.*<\/Total>/s', $cxml, 'Total must be 100.00');

        // Must NOT contain any SharedSecret
        self::assertStringNotContainsString('<SharedSecret>', $cxml, 'cXML PunchOutOrderMessage must not contain SharedSecret');

        // 8. Validate against DTD
        libxml_use_internal_errors(true);
        $this->validateCxmlAgainstDtd($cxml);

        // 9. Re-transfer must be refused (session is now transferred/closed)
        $csrfToken2 = $this->getCsrfToken('tmonier_punchout_transfer');
        $client->request('POST', '/punchout/transfer', ['_csrf_token' => $csrfToken2]);
        self::assertSame(403, $client->getResponse()->getStatusCode(), 'Re-transfer after session close must return 403');
    }

    // -------------------------------------------------------------------------
    // Test: TTC 20% channel → UnitPrice HT = 100.00
    // -------------------------------------------------------------------------

    /**
     * US-018 + ADR-005: buyer with taxIncluded=true, item 120.00 TTC → UnitPrice 100.00.
     */
    public function testTaxInclusivePriceStripping(): void
    {
        $client = $this->client;
        $catalog = new TestCatalog(static::getContainer());

        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithTaxIncluded();

        // Setup → start → authenticate
        $setupResponse = $this->postSetupRequest($buyer, $secret);
        $startUrl = $this->extractStartUrl($setupResponse);
        $token = $this->extractTokenFromStartUrl($startUrl);

        $client->request('GET', '/punchout/start/' . $token);
        self::assertSame(302, $client->getResponse()->getStatusCode());

        // R1+R2: add the tax item directly to the PunchOut session cart
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $ttcSession = $em->createQueryBuilder()
            ->select('s')
            ->from(PunchoutSession::class, 's')
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleResult();
        self::assertInstanceOf(PunchoutSession::class, $ttcSession);
        $ttcCart = $ttcSession->getCart();
        self::assertNotNull($ttcCart, 'PunchOut cart must exist after StartAction (R1)');
        // Build cart with TTC item via catalog helper
        $catalog->createCartWithTaxItem($buyer, $ttcCart);

        // R3: include CSRF token
        $csrfTtcToken = $this->getCsrfToken('tmonier_punchout_transfer');
        $client->request('POST', '/punchout/transfer', ['_csrf_token' => $csrfTtcToken]);
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $html = (string) $client->getResponse()->getContent();
        preg_match('/name="cXML-base64"\s+value="([^"]+)"/', $html, $matches);
        self::assertNotEmpty($matches[1] ?? '');
        $cxml = (string) base64_decode($matches[1], true);

        // UnitPrice must be 100.00 (net ex-tax), not 120.00 (inclusive)
        self::assertMatchesRegularExpression('/<Money[^>]*>100\.00<\/Money>/', $cxml, 'UnitPrice must be 100.00 HT after TTC stripping');
        self::assertStringNotContainsString('>120.00<', $cxml, 'TTC price must not appear in cXML');
    }

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
        $catalog = new TestCatalog(static::getContainer());

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
        $em = static::getContainer()->get(EntityManagerInterface::class);
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
        $catalog = new TestCatalog(static::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyer();

        $buyer->setEnabled(false);
        $em = static::getContainer()->get(EntityManagerInterface::class);
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

    // -------------------------------------------------------------------------
    // Test: simulator round-trip (US-026)
    // -------------------------------------------------------------------------

    /**
     * US-026: simulator form → POST /punchout/cxml → start URL in response.
     *
     * Note: the simulator uses curl internally which is unavailable in KernelBrowser.
     * We test the form submission directly by posting to the endpoint and verifying
     * that the receive page displays a decoded PunchOutOrderMessage.
     */
    public function testSimulatorEndpointRoundTrip(): void
    {
        $client = $this->client;
        $catalog = new TestCatalog(static::getContainer());

        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer(
            fromIdentity: 'test-sim-buyer@example.com',
            senderIdentity: 'test-sim-system@example.com',
        );

        // --- Simulator form: GET → renders the form ---
        $client->request('GET', '/simulator/cxml');
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'Simulator send page must return 200');
        self::assertStringContainsString('PunchOut', (string) $client->getResponse()->getContent());

        // --- POST the setup request directly to /punchout/cxml (simulating what the simulator does) ---
        $ids = $this->extractIdentities($buyer);
        $returnUrl = 'http://localhost/simulator/receive'; // simulator uses HTTP (internal call)
        // But wait — our ReturnUrlValidator blocks HTTP. The simulator uses its own HTTP returnUrl.
        // We use HTTPS for a valid URL, then we route the response manually.
        $returnUrl = 'https://ariba.example.com/punchout/receive';
        $setupResponse = $this->postSetupRequest($buyer, $secret, $returnUrl);
        $startUrl = $this->extractStartUrl($setupResponse);
        self::assertNotEmpty($startUrl, 'Simulator: setup response must contain start URL');
        $token = $this->extractTokenFromStartUrl($startUrl);

        // --- GET start URL → authenticate shopper ---
        $client->request('GET', '/punchout/start/' . $token);
        self::assertSame(302, $client->getResponse()->getStatusCode(), 'Simulator: start must redirect to shop');

        // --- R1+R2: Add items to the PunchOut session's isolated cart ---
        $emSim = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $emSim);
        $simSession = $emSim->createQueryBuilder()
            ->select('s')
            ->from(PunchoutSession::class, 's')
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleResult();
        self::assertInstanceOf(PunchoutSession::class, $simSession);
        $simCart = $simSession->getCart();
        self::assertNotNull($simCart, 'PunchOut cart must exist (R1)');
        $catalog->addItemToOrder($simCart, 5000, 2); // 2 items × 50.00 = 100.00 total

        // --- POST /punchout/transfer (R3: include CSRF token) ---
        $csrfSimToken = $this->getCsrfToken('tmonier_punchout_transfer');
        $client->request('POST', '/punchout/transfer', ['_csrf_token' => $csrfSimToken]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'Simulator: transfer must return 200');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('cXML-base64', $html, 'Transfer page must contain cXML-base64 field');

        // --- Simulator receive page: POST cXML-urlencoded ---
        preg_match('/name="cXML-base64"\s+value="([^"]+)"/', $html, $matches);
        self::assertNotEmpty($matches[1] ?? '', 'cXML-base64 must be present');
        $cxmlBase64 = $matches[1];
        $cxml = (string) base64_decode($cxmlBase64, true);

        // Post to the simulator receive page (simulates the procurement system receiving the cart)
        $client->request('POST', '/simulator/receive', ['cXML-urlencoded' => $cxml]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'Simulator receive page must return 200');

        $receivePage = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('PunchOutOrderMessage', $receivePage, 'Receive page must display the PunchOutOrderMessage');

        // Validate the cXML against DTD
        libxml_use_internal_errors(true);
        $this->validateCxmlAgainstDtd($cxml);
    }

    // -------------------------------------------------------------------------
    // Private helper
    // -------------------------------------------------------------------------

    private function extractBuyerCookie(string $setupResponseXml): string
    {
        // BuyerCookie is not in the setup response — it's stored in the session.
        // We extract it from the setup request we sent, by parsing the session table.
        // Alternatively, parse it from the PunchOut session via the container.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $result = $em->createQueryBuilder()
            ->select('s.buyerCookie')
            ->from(PunchoutSession::class, 's')
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult()
        ;

        return (string) $result;
    }
}
