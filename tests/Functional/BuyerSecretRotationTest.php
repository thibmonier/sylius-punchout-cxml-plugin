<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerIdentity;

/** US-002: secret rotation without downtime, imposed secret, several identities. */
final class BuyerSecretRotationTest extends WebTestCase
{
    use DatabaseTransactionTrait;
    use PunchoutRoundTripTrait;

    public function testANewSecretKeepsThePreviousOneValidUntilItIsRevoked(): void
    {
        ['buyer' => $buyer, 'secret' => $secretA] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();
        $this->loginAdmin();

        $secretB = $this->generateSecret($buyer);
        self::assertSame('200', $this->setupStatus($buyer, $secretA), 'The previous secret still works during the rotation');
        self::assertSame('200', $this->setupStatus($buyer, $secretB));

        $crawler = $this->client->request('GET', $this->editUrl($buyer));
        $revoke = $crawler->filter('form[data-test-tmonier-punchout-revoke-secret]');
        self::assertCount(2, $revoke, 'Each active secret can be revoked');
        $oldest = $this->buyer($buyer)->getActiveCredentials()[0];
        $this->client->submit($crawler->filter('#tmonier-punchout-revoke-secret-' . $oldest->getId())->form());
        self::assertTrue($this->client->getResponse()->isRedirect(), substr((string) $this->client->getResponse()->getContent(), 0, 600));
        self::assertCount(1, $this->buyer($buyer)->getActiveCredentials());

        self::assertSame('401', $this->setupStatus($buyer, $secretA));
        self::assertSame('200', $this->setupStatus($buyer, $secretB));
    }

    public function testAtMostTwoSecretsAreActive(): void
    {
        ['buyer' => $buyer, 'secret' => $secretA] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();
        $this->loginAdmin();

        $this->generateSecret($buyer);
        $secretC = $this->generateSecret($buyer);

        self::assertCount(2, $this->buyer($buyer)->getActiveCredentials());
        self::assertSame('401', $this->setupStatus($buyer, $secretA), 'The oldest secret is revoked');
        self::assertSame('200', $this->setupStatus($buyer, $secretC));
    }

    public function testASecretImposedByTheProcurementSystemCanBeEntered(): void
    {
        ['buyer' => $buyer] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();
        $this->loginAdmin();

        $this->setSecret($buyer, 'too-short');
        self::assertStringContainsString('at least 16 characters', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $this->buyer($buyer)->getActiveCredentials());

        $imposed = 'imposed-' . bin2hex(random_bytes(12));
        $this->setSecret($buyer, $imposed);
        self::assertStringNotContainsString($imposed, (string) $this->client->getResponse()->getContent(), 'An imposed secret is never displayed');
        self::assertSame('200', $this->setupStatus($buyer, $imposed));
    }

    public function testSeveralIdentitiesDesignateTheSameBuyer(): void
    {
        ['buyer' => $buyer, 'secret' => $secret] = (new TestCatalog(self::getContainer()))->createBuyerWithCustomer();
        $this->loginAdmin();
        $duns = (string) random_int(10000000, 99999999);
        $crawler = $this->client->request('GET', $this->editUrl($buyer));
        $form = $crawler->filter('form[name="tmonier_punchout_buyer"]')->form();
        $current = $form->getValues()['tmonier_punchout_buyer[fromIdentities]'] ?? '';
        $form['tmonier_punchout_buyer[fromIdentities]'] = (is_string($current) ? $current : '') . "\nDUNS " . $duns;
        $this->client->submit($form);
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());

        self::assertSame('200', $this->setupStatus($buyer, $secret), 'The first identity still works');
        self::assertSame('200', $this->setupStatus($buyer, $secret, ['DUNS', $duns]));
        self::assertSame('200', $this->setupStatus($buyer, $secret, ['duns', $duns]), 'cXML domains are case-insensitive');
        self::assertCount(2, array_filter($this->buyer($buyer)->getIdentities()->toArray(), static fn (BuyerIdentity $i): bool => $i->getRole() === BuyerIdentity::ROLE_FROM));
    }

    /** @param array{string, string}|null $from */
    private function setupStatus(Buyer $buyer, string $secret, ?array $from = null): string
    {
        $body = $this->postSetup($this->setupRequestXml($this->buyer($buyer), $secret, 'C', from: $from));

        return 1 === preg_match('/<Status code="(\d+)"/', $body, $matches) ? $matches[1] : $body;
    }

    private function generateSecret(Buyer $buyer): string
    {
        $crawler = $this->client->request('GET', $this->editUrl($buyer));
        $this->client->submit($crawler->filter('#tmonier-punchout-regenerate-secret')->form());
        $crawler = $this->client->followRedirect();

        return trim($crawler->filter('[data-test-tmonier-punchout-secret-value]')->text());
    }

    private function setSecret(Buyer $buyer, string $secret): void
    {
        $crawler = $this->client->request('GET', $this->editUrl($buyer));
        $this->client->submit($crawler->filter('#tmonier-punchout-set-secret')->form(['secret' => $secret]));
        $this->client->followRedirect();
    }

    private function editUrl(Buyer $buyer): string
    {
        return '/admin/punchout-buyers/' . $buyer->getId() . '/edit';
    }

    private function buyer(Buyer $buyer): Buyer
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $fresh = $em->find(Buyer::class, $buyer->getId());
        self::assertInstanceOf(Buyer::class, $fresh);

        return $fresh;
    }

    private function loginAdmin(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $admin = $em->getRepository(AdminUserInterface::class)->findOneBy(['username' => 'sylius']);
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }
}
