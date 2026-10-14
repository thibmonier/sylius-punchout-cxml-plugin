<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TransferActionTest extends WebTestCase
{
    use DatabaseTransactionTrait;

    public function testTransferWithoutSessionReturns403(): void
    {
        $client = self::createClient();
        $client->request('POST', '/punchout/transfer');

        // Without an active punchout session, should return 403
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }
}
