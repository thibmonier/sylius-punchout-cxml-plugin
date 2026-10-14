<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TransferActionTest extends WebTestCase
{
    use DatabaseTransactionTrait;

    public function testTransferWithoutSessionReturns403(): void
    {
        $client = $this->client;
        $client->request('POST', '/punchout/transfer');

        // Without an active punchout session, should return 403
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
}
