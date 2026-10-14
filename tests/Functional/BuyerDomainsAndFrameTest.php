<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;

/** US-004 (allowed return domains, deny by default) and US-016 (iframe headers). */
final class BuyerDomainsAndFrameTest extends WebTestCase
{
    use DatabaseTransactionTrait;
    use PunchoutRoundTripTrait;

    public function testOnlyAReturnUrlOfAnAllowedDomainOpensASession(): void
    {
        ['buyer' => $buyer, 'secret' => $secret] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer(returnDomains: ['*.ariba.com']);

        $cases = [
            'https://s1.ariba.com/punchout/return' => '200',
            'https://ariba.com/punchout/return' => '403',
            'https://attacker.example/collect' => '403',
            'https://s1.ariba.com.attacker.example/collect' => '403',
            'http://10.0.0.5/return' => '403',
        ];
        foreach ($cases as $url => $status) {
            self::assertStringContainsString('<Status code="' . $status . '"', $this->postSetup($this->setupRequestXml($buyer, $secret, 'C', $url)), $url);
        }
    }

    public function testABuyerWithoutAllowedDomainAcceptsNoReturnUrl(): void
    {
        ['buyer' => $buyer, 'secret' => $secret] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer(returnDomains: []);

        self::assertStringContainsString('<Status code="403"', $this->postSetup($this->setupRequestXml($buyer, $secret, 'C')));
    }

    public function testPunchoutPagesMayBeFramedByTheBuyerOnly(): void
    {
        ['buyer' => $buyer, 'secret' => $secret] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer(iframeOrigins: ['https://s1.ariba.com']);

        $this->client->request('GET', '/en_US/');
        self::assertSame('sameorigin', strtolower((string) $this->client->getResponse()->headers->get('X-Frame-Options')), 'Shop pages keep X-Frame-Options outside a PunchOut session');

        $this->startPunchout($buyer, $secret);
        $this->client->request('GET', '/en_US/cart/');
        $headers = $this->client->getResponse()->headers;
        self::assertNull($headers->get('X-Frame-Options'));
        self::assertSame("frame-ancestors 'self' https://s1.ariba.com", $headers->get('Content-Security-Policy'));
    }
}
