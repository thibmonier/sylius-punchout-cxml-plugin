<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;

/** US-010: the procurement system reads the transactions this supplier supports. */
final class CxmlProfileTest extends WebTestCase
{
    use DatabaseTransactionTrait;
    use PunchoutRoundTripTrait;

    public function testAnAuthenticatedProfileRequestListsThePunchoutTransaction(): void
    {
        ['buyer' => $buyer, 'secret' => $secret] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();

        $response = $this->postSetup($this->profileRequest($buyer, $secret));

        self::assertStringContainsString('<Status code="200"', $response);
        self::assertMatchesRegularExpression('#<Transaction requestName="PunchOutSetupRequest">\s*<URL>http://localhost/punchout/cxml</URL>\s*<Option name="operationAllowed">create edit inspect</Option>#', $response);
        self::assertStringContainsString('<Transaction requestName="ProfileRequest">', $response);
        $this->assertValidAgainstTheDtd($response);
    }

    public function testAProfileRequestWithAWrongSecretIsUnauthorized(): void
    {
        ['buyer' => $buyer] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();

        self::assertStringContainsString('<Status code="401"', $this->postSetup($this->profileRequest($buyer, 'wrong-' . bin2hex(random_bytes(8)))));
    }

    public function testTheSetupResponseIsValidAndKeepsOnlyTheAllowedExtrinsics(): void
    {
        ['buyer' => $buyer, 'secret' => $secret] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();
        $setup = str_replace(
            '<BuyerCookie>',
            '<Extrinsic name="UserEmail">claire@bigcorp.example</Extrinsic><Extrinsic name="PhoneNumber">0102030405</Extrinsic><BuyerCookie>',
            $this->setupRequestXml($buyer, $secret, 'C'),
        );

        $response = $this->postSetup($setup);

        self::assertStringContainsString('<Status code="200"', $response);
        $this->assertValidAgainstTheDtd($response);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $session = $em->getRepository(PunchoutSession::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(PunchoutSession::class, $session);
        self::assertSame(['UserEmail' => 'claire@bigcorp.example'], $session->getExtrinsics());
    }

    private function profileRequest(BuyerInterface $buyer, string $secret): string
    {
        $setup = $this->setupRequestXml($buyer, $secret, 'C');

        return (string) preg_replace('#<PunchOutSetupRequest.*</PunchOutSetupRequest>#s', '<ProfileRequest/>', $setup);
    }
}
