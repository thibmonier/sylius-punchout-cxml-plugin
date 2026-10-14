<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;

/** US-008 (replay, rate limit) and US-009 (statuses) on the cXML endpoint. */
final class CxmlProtectionTest extends WebTestCase
{
    use DatabaseTransactionTrait;
    use PunchoutRoundTripTrait;

    public function testAReplayedSetupIsRefused(): void
    {
        ['buyer' => $buyer, 'secret' => $secret] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();
        $xml = $this->setupRequestXml($buyer, $secret, 'C', payloadId: '123.' . bin2hex(random_bytes(4)) . '@buyer');

        self::assertStringContainsString('<Status code="200"', $this->postSetup($xml));
        $sessions = $this->sessionCount();

        self::assertStringContainsString('<Status code="400"', $this->postSetup($xml));
        self::assertSame($sessions, $this->sessionCount(), 'No new session for a replayed setup');
    }

    public function testASetupOutsideTheTimestampWindowIsRefused(): void
    {
        ['buyer' => $buyer, 'secret' => $secret] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();

        foreach (['-16 minutes', '+16 minutes'] as $shift) {
            $timestamp = (new \DateTimeImmutable($shift))->format(\DateTimeInterface::RFC3339);
            self::assertStringContainsString('<Status code="400"', $this->postSetup($this->setupRequestXml($buyer, $secret, 'C', timestamp: $timestamp)), $shift);
        }
        $timestamp = (new \DateTimeImmutable('-5 minutes'))->format(\DateTimeInterface::RFC3339);
        self::assertStringContainsString('<Status code="200"', $this->postSetup($this->setupRequestXml($buyer, $secret, 'C', timestamp: $timestamp)));
    }

    public function testABurstFromOneAddressIsRateLimited(): void
    {
        $ip = '198.51.100.' . random_int(1, 254);
        $statuses = [];
        for ($i = 0; $i < 31; ++$i) {
            $this->postSetup('<cXML/>', $ip);
            $statuses[] = $this->client->getResponse()->getStatusCode();
        }

        self::assertSame(429, end($statuses));
        self::assertNotContains(429, array_slice($statuses, 0, 30));
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));

        $this->postSetup('<cXML/>', '192.0.2.' . random_int(1, 254));
        self::assertNotSame(429, $this->client->getResponse()->getStatusCode(), 'Other addresses are not limited');
    }

    public function testRepeatedAuthenticationFailuresOfAnIdentityAreLimitedWhateverTheAddress(): void
    {
        ['buyer' => $buyer] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer(fromIdentity: 'bruteforce-' . bin2hex(random_bytes(4)) . '@example.com');

        $statuses = [];
        for ($i = 1; $i <= 11; ++$i) {
            $body = $this->postSetup($this->setupRequestXml($buyer, 'wrong-secret-' . $i, 'C'), '198.18.0.' . $i);
            $statuses[] = $this->client->getResponse()->getStatusCode() === 429 ? '429' : (1 === preg_match('/<Status code="(\d+)"/', $body, $m) ? $m[1] : '?');
        }

        self::assertSame(array_fill(0, 10, '401'), array_slice($statuses, 0, 10));
        self::assertSame('429', $statuses[10], 'Brute force on one identity is limited even from many addresses');
    }

    public function testATimestampWithoutAnAbsoluteDateIsRefused(): void
    {
        ['buyer' => $buyer, 'secret' => $secret] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();

        foreach (['now', '+1 sec', (new \DateTimeImmutable())->format('Y-m-d H:i:s')] as $timestamp) {
            self::assertStringContainsString('<Status code="400"', $this->postSetup($this->setupRequestXml($buyer, $secret, 'C', timestamp: $timestamp)), $timestamp);
        }
    }

    public function testAnUnsupportedOperationIsNotImplemented(): void
    {
        ['buyer' => $buyer, 'secret' => $secret] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();

        self::assertStringContainsString('<Status code="450"', $this->postSetup($this->setupRequestXml($buyer, $secret, 'C', operation: 'source')));
    }

    public function testAMalformedSetupIsABadRequest(): void
    {
        self::assertStringContainsString('<Status code="400"', $this->postSetup('<?xml version="1.0"?><cXML payloadID="x" timestamp="y"><Header/></cXML>'));
    }

    public function testAnEarlierDtdVersionIsAccepted(): void
    {
        ['buyer' => $buyer, 'secret' => $secret] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();

        self::assertStringContainsString('<Status code="200"', $this->postSetup($this->setupRequestXml($buyer, $secret, 'C', dtdVersion: '1.2.014')));
    }

    private function sessionCount(): int
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return (int) $em->createQueryBuilder()->select('COUNT(s.id)')->from(PunchoutSession::class, 's')->getQuery()->getSingleScalarResult();
    }
}
