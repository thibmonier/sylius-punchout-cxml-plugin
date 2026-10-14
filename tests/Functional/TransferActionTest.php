<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerIdentity;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;

final class TransferActionTest extends WebTestCase
{
    use DatabaseTransactionTrait;

    public function testTransferWithoutSessionReturns403(): void
    {
        $client = $this->client;
        $client->request('POST', '/punchout/transfer');

        // Without an active punchout session, should return 403 (session check is first)
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    /**
     * R16: The transfer response must include security headers:
     * - Cache-Control: no-store
     * - Content-Security-Policy with nonce-based script-src and form-action
     * - The nonce in the CSP header must appear in the HTML body
     */
    public function testTransferResponseHasNoStoreCacheControl(): void
    {
        $client = $this->client;
        $client->request('POST', '/punchout/transfer');

        // Even a 403 must not cache
        $cacheControl = $client->getResponse()->headers->get('Cache-Control') ?? '';
        self::assertStringContainsString('no-store', $cacheControl);
    }

    /**
     * R3: A POST /punchout/transfer with an active session but without a valid CSRF
     * token must return 422 Unprocessable Entity.
     */
    public function testTransferWithoutCsrfTokenReturns422(): void
    {
        $client = $this->client;
        $catalog = new TestCatalog(self::getContainer());

        // Authenticate via start URL to establish active PunchOut session
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer(
            fromIdentity: 'test-csrf-buyer@example.com',
            senderIdentity: 'test-csrf-system@example.com',
        );
        $this->performSetupAndStart($buyer, $secret);

        // POST /punchout/transfer WITHOUT CSRF token
        $client->request('POST', '/punchout/transfer');

        self::assertSame(422, $client->getResponse()->getStatusCode(), 'Transfer without CSRF must return 422');
    }

    /**
     * R6: A POST /punchout/transfer where the session's returnUrl has been tampered
     * to point at a private IP must be rejected with 403.
     *
     * This test requires R1 (PunchOut cart), R2 (session cart resolution), R3 (CSRF).
     */
    public function testTransferWithTamperedReturnUrlReturns403(): void
    {
        $client = $this->client;
        $catalog = new TestCatalog(self::getContainer());

        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer(
            fromIdentity: 'test-r6-buyer@example.com',
            senderIdentity: 'test-r6-system@example.com',
        );
        $this->performSetupAndStart($buyer, $secret);

        // Add items to the PunchOut session's cart
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $session = $em->createQueryBuilder()
            ->select('s')
            ->from(PunchoutSession::class, 's')
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleResult();
        self::assertInstanceOf(PunchoutSession::class, $session);

        // Add item to the PunchOut cart (required for transfer to proceed past cart-not-found check)
        $punchoutCart = $session->getCart();
        if ($punchoutCart instanceof \Sylius\Component\Core\Model\OrderInterface) {
            $catalog->addItemToOrder($punchoutCart, 10000, 1);
        }

        // Tamper the session's returnUrl to a private IP (HTTPS but private range)
        $session->setReturnUrl('https://192.168.1.100/evil-procurement-system');
        $em->flush();

        // POST with a valid CSRF token (R3 compliant)
        $csrfToken = $this->getCsrfToken('tmonier_punchout_transfer');
        $client->request('POST', '/punchout/transfer', ['_csrf_token' => $csrfToken]);

        self::assertSame(403, $client->getResponse()->getStatusCode(), 'Transfer with tampered private-IP returnUrl must return 403');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Posts a PunchOut setup request and follows the start URL to establish an
     * authenticated session for the given buyer.
     */
    private function performSetupAndStart(BuyerInterface $buyer, string $secret): void
    {
        $client = $this->client;
        $returnUrl = 'https://ariba.example.com/return';

        $identities = $buyer->getIdentities();
        $fromId = $identities->filter(fn (BuyerIdentity $i): bool => $i->getRole() === BuyerIdentity::ROLE_FROM)->first();
        $senderId = $identities->filter(fn (BuyerIdentity $i): bool => $i->getRole() === BuyerIdentity::ROLE_SENDER)->first();
        self::assertInstanceOf(BuyerIdentity::class, $fromId);
        self::assertInstanceOf(BuyerIdentity::class, $senderId);

        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339);
        $payloadId = bin2hex(random_bytes(8)) . '.' . time() . '@test.local';
        $xml = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE cXML SYSTEM "http://xml.cxml.org/schemas/cXML/1.2.071/cXML.dtd">
            <cXML payloadID="{$payloadId}" timestamp="{$timestamp}">
                <Header>
                    <From><Credential domain="{$fromId->getDomain()}"><Identity>{$fromId->getIdentity()}</Identity></Credential></From>
                    <To><Credential domain="NetworkId"><Identity>demo-supplier@example.com</Identity></Credential></To>
                    <Sender>
                        <Credential domain="{$senderId->getDomain()}">
                            <Identity>{$senderId->getIdentity()}</Identity>
                            <SharedSecret>{$secret}</SharedSecret>
                        </Credential>
                        <UserAgent>TestClient/1.0</UserAgent>
                    </Sender>
                </Header>
                <Request deploymentMode="test">
                    <PunchOutSetupRequest operation="create">
                        <BuyerCookie>COOKIE-{$payloadId}</BuyerCookie>
                        <BrowserFormPost><URL>{$returnUrl}</URL></BrowserFormPost>
                    </PunchOutSetupRequest>
                </Request>
            </cXML>
            XML;

        $client->request('POST', '/punchout/cxml', [], [], ['CONTENT_TYPE' => 'text/xml; charset=UTF-8'], $xml);
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'Setup must return 200');

        $setupBody = (string) $client->getResponse()->getContent();
        $doc = new \DOMDocument();
        $doc->loadXML($setupBody, \LIBXML_NONET);
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//StartPage/URL');
        self::assertNotFalse($nodes);
        self::assertGreaterThan(0, $nodes->length);
        $node = $nodes->item(0);
        self::assertInstanceOf(\DOMElement::class, $node);
        $startUrl = $node->textContent;

        $parts = explode('/punchout/start/', $startUrl);
        $token = trim($parts[1] ?? '', '/');
        self::assertNotEmpty($token);

        $client->request('GET', '/punchout/start/' . $token);
        self::assertSame(302, $client->getResponse()->getStatusCode(), 'Start token must redirect');
    }
}
