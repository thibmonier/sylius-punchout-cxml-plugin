<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SimulatorTest extends WebTestCase
{
    public function testSimulatorPageLoads(): void
    {
        $client = self::createClient();
        $client->request('GET', '/simulator/cxml');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('PunchOut', (string) $response->getContent());
    }

    public function testSimulatorReceivePageLoads(): void
    {
        $client = self::createClient();
        $client->request('GET', '/simulator/receive');

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }
}
