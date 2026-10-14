<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerIdentity;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;

/**
 * Drives a PunchOut session through real HTTP requests: setup, start, cart, transfer.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\WebTestCase
 */
trait PunchoutRoundTripTrait
{
    /** @param array{string, string}|null $from From credential (domain, identity) instead of the buyer's first one */
    protected function setupRequestXml(
        BuyerInterface $buyer,
        string $secret,
        string $buyerCookie,
        string $returnUrl = 'https://ariba.example.com/return',
        ?string $payloadId = null,
        ?string $timestamp = null,
        string $operation = 'create',
        string $dtdVersion = '1.2.071',
        ?array $from = null,
    ): string {
        $fromIdentity = $this->identity($buyer, BuyerIdentity::ROLE_FROM);
        [$fromDomain, $fromValue] = $from ?? [$fromIdentity->getDomain(), $fromIdentity->getIdentity()];
        $sender = $this->identity($buyer, BuyerIdentity::ROLE_SENDER);
        $timestamp ??= (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339);
        $payloadId ??= bin2hex(random_bytes(8)) . '@test.local';

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE cXML SYSTEM "http://xml.cxml.org/schemas/cXML/{$dtdVersion}/cXML.dtd">
            <cXML payloadID="{$payloadId}" timestamp="{$timestamp}">
                <Header>
                    <From><Credential domain="{$fromDomain}"><Identity>{$fromValue}</Identity></Credential></From>
                    <To><Credential domain="NetworkId"><Identity>supplier</Identity></Credential></To>
                    <Sender>
                        <Credential domain="{$sender->getDomain()}"><Identity>{$sender->getIdentity()}</Identity><SharedSecret>{$secret}</SharedSecret></Credential>
                        <UserAgent>Test</UserAgent>
                    </Sender>
                </Header>
                <Request deploymentMode="test">
                    <PunchOutSetupRequest operation="{$operation}">
                        <BuyerCookie>{$buyerCookie}</BuyerCookie>
                        <BrowserFormPost><URL>{$returnUrl}</URL></BrowserFormPost>
                    </PunchOutSetupRequest>
                </Request>
            </cXML>
            XML;
    }

    /** Posts a setup request as the procurement system does and returns the cXML response. */
    protected function postSetup(string $xml, string $ip = '203.0.113.10'): string
    {
        $this->client->request('POST', '/punchout/cxml', [], [], ['CONTENT_TYPE' => 'text/xml', 'REMOTE_ADDR' => $ip], $xml);

        return (string) $this->client->getResponse()->getContent();
    }

    /** Posts a setup request and opens the session in the browser. */
    protected function startPunchout(BuyerInterface $buyer, string $secret, string $buyerCookie = 'COOKIE-TEST'): void
    {
        $this->client->request('POST', '/punchout/cxml', [], [], ['CONTENT_TYPE' => 'text/xml'], $this->setupRequestXml($buyer, $secret, $buyerCookie));
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<Status code="200"', $body, $body);
        if (1 !== preg_match('#<URL>[^<]*/punchout/start/([^<]+)</URL>#', $body, $matches)) {
            self::fail($body);
        }

        $this->client->request('GET', '/punchout/start/' . $matches[1]);
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());
    }

    /** @return array{id: int|null, items: list<array{variant: string|null, quantity: int}>} */
    protected function currentCart(): array
    {
        $this->client->request('GET', '/simulator/test/cart');
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** @return array{id: int|null, items: list<array{variant: string|null, quantity: int}>} */
    protected function addToCurrentCart(string $variantCode, int $quantity): array
    {
        $this->client->request('POST', '/simulator/test/cart/add', ['variant' => $variantCode, 'quantity' => (string) $quantity]);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** Transfers the cart and returns the decoded PunchOutOrderMessage. */
    protected function transfer(): string
    {
        $this->client->request('POST', '/punchout/transfer', ['_csrf_token' => $this->getCsrfToken('tmonier_punchout_transfer')]);
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        if (1 !== preg_match('/name="cXML-base64"\s+value="([^"]+)"/', $html, $matches)) {
            self::fail($html);
        }
        $xml = base64_decode($matches[1], true);
        self::assertIsString($xml);

        // Indentation between tags is irrelevant for the assertions.
        return (string) preg_replace('/>\s+</', '><', $xml);
    }

    /** @return array{id: int|null, items: list<array{variant: string|null, quantity: int}>} */
    private function json(): array
    {
        /** @var array{id: int|null, items: list<array{variant: string|null, quantity: int}>} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $data;
    }

    private function identity(BuyerInterface $buyer, string $role): BuyerIdentity
    {
        foreach ($buyer->getIdentities() as $identity) {
            if ($identity->getRole() === $role) {
                return $identity;
            }
        }

        throw new \LogicException('Missing ' . $role . ' identity');
    }
}
