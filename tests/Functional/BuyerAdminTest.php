<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerIdentity;

/**
 * US-001: Sophie creates a PunchOut buyer linked to a Sylius customer; the shared secret is
 * generated, stored as a hash only and shown once.
 */
final class BuyerAdminTest extends WebTestCase
{
    use DatabaseTransactionTrait;
    use PunchoutRoundTripTrait;

    public function testTheBuyerListIsAvailable(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', '/admin/punchout-buyers/');

        self::assertResponseIsSuccessful();
    }

    public function testCreatingABuyerShowsItsSecretOnceAndTheSecretOpensASession(): void
    {
        $this->loginAdmin();
        $code = 'bigcorp-' . bin2hex(random_bytes(3));

        $this->submitCreateForm($code, 'achats-' . bin2hex(random_bytes(3)) . '@bigcorp.example');

        self::assertTrue($this->client->getResponse()->isRedirect(), $this->errors());
        $this->client->followRedirect();
        $secret = trim($this->client->getCrawler()->filter('[data-test-tmonier-punchout-secret-value]')->text());
        self::assertGreaterThanOrEqual(43, strlen($secret), 'A 256-bit secret is shown');

        $buyer = $this->buyer($code);
        self::assertSame($this->customer()->getId(), $buyer->getCustomer()?->getId());
        self::assertCount(1, $buyer->getActiveCredentials());
        self::assertTrue(password_verify($secret, $buyer->getActiveCredentials()[0]->getPasswordHash()), 'Only the hash is stored');
        self::assertStringNotContainsString($secret, $buyer->getActiveCredentials()[0]->getPasswordHash());

        $this->client->request('GET', '/admin/punchout-buyers/' . $buyer->getId() . '/edit');
        self::assertCount(0, $this->client->getCrawler()->filter('[data-test-tmonier-punchout-secret-value]'), 'The secret is shown only once');

        $this->client->request('GET', '/en_US/logout');
        $this->startPunchout($buyer, $secret);
    }

    public function testTheCustomerIsMandatory(): void
    {
        $this->loginAdmin();
        $code = 'nocustomer-' . bin2hex(random_bytes(3));

        $this->submitCreateForm($code, 'x-' . bin2hex(random_bytes(3)) . '@bigcorp.example', customer: '');

        self::assertFalse($this->client->getResponse()->isRedirect(), 'The invalid form is shown again');
        self::assertStringContainsString('A Sylius customer is required.', $this->errors());
        self::assertNull($this->entityManager()->getRepository(Buyer::class)->findOneBy(['code' => $code]));
    }

    public function testAtLeastOneValidReturnDomainIsRequired(): void
    {
        $this->loginAdmin();

        $this->submitCreateForm('nodomain-' . bin2hex(random_bytes(3)), 'a-' . bin2hex(random_bytes(3)) . '@bigcorp.example', returnDomains: '');
        self::assertStringContainsString('Enter at least one allowed return domain.', $this->errors());

        $this->submitCreateForm('baddomain-' . bin2hex(random_bytes(3)), 'b-' . bin2hex(random_bytes(3)) . '@bigcorp.example', returnDomains: 'https://ariba.com/return');
        self::assertStringContainsString('Invalid domain: use ariba.com or *.ariba.com.', $this->errors());
    }

    public function testTheIframeOriginsAreValidatedAndSaved(): void
    {
        $this->loginAdmin();

        $this->submitCreateForm('badframe-' . bin2hex(random_bytes(3)), 'c-' . bin2hex(random_bytes(3)) . '@bigcorp.example', iframeOrigins: 'http://s1.ariba.com');
        self::assertStringContainsString('Invalid origin: use https://host', $this->errors());

        $code = 'frame-' . bin2hex(random_bytes(3));
        $this->submitCreateForm($code, 'd-' . bin2hex(random_bytes(3)) . '@bigcorp.example', returnDomains: "*.Ariba.com\nariba.example.com", iframeOrigins: "https://S1.ariba.com/\nhttps://s2.ariba.com");
        self::assertTrue($this->client->getResponse()->isRedirect(), $this->errors());
        $buyer = $this->buyer($code);
        self::assertSame(['*.ariba.com', 'ariba.example.com'], $buyer->getReturnDomains());
        self::assertSame(['https://s1.ariba.com', 'https://s2.ariba.com'], $buyer->getIframeOrigins());
        self::assertTrue($buyer->isIncludeTax());
        self::assertNotTrue($buyer->getIncludeShipping());
    }

    public function testAFromIdentityBelongsToASingleBuyer(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $existing] = $catalog->createBuyerWithCustomer(fromDomain: 'NetworkId', fromIdentity: 'shared-' . bin2hex(random_bytes(3)) . '@bigcorp.example');
        $taken = '';
        foreach ($existing->getIdentities() as $identity) {
            if ($identity->getRole() === BuyerIdentity::ROLE_FROM) {
                $taken = $identity->getIdentity();
            }
        }
        $this->loginAdmin();
        $code = 'duplicate-' . bin2hex(random_bytes(3));

        $this->submitCreateForm($code, $taken);

        self::assertFalse($this->client->getResponse()->isRedirect(), 'The invalid form is shown again');
        self::assertStringContainsString('This identity is already used by another PunchOut buyer.', $this->errors());
    }

    private function submitCreateForm(string $code, string $fromIdentity, ?string $customer = null, string $returnDomains = "ariba.example.com\n", string $iframeOrigins = ''): void
    {
        $crawler = $this->client->request('GET', '/admin/punchout-buyers/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="tmonier_punchout_buyer"]')->form();
        $form['tmonier_punchout_buyer[code]'] = $code;
        $form['tmonier_punchout_buyer[name]'] = 'BigCorp Ariba';
        $form['tmonier_punchout_buyer[protocol]'] = 'cxml';
        $form['tmonier_punchout_buyer[channel]'] = (string) $this->channel()->getCode();
        $form['tmonier_punchout_buyer[customer]'] = $customer ?? (string) $this->customer()->getEmail();
        $form['tmonier_punchout_buyer[fromIdentities]'] = 'NetworkId ' . $fromIdentity;
        $form['tmonier_punchout_buyer[senderIdentities]'] = 'NetworkId AN-' . bin2hex(random_bytes(3));
        $form['tmonier_punchout_buyer[returnDomains]'] = $returnDomains;
        $form['tmonier_punchout_buyer[iframeOrigins]'] = $iframeOrigins;
        $includeTax = $form['tmonier_punchout_buyer[includeTax]'];
        self::assertInstanceOf(ChoiceFormField::class, $includeTax);
        $includeTax->tick();
        $this->client->submit($form);
    }

    private function errors(): string
    {
        $crawler = new Crawler((string) $this->client->getResponse()->getContent());

        return implode(' | ', $crawler->filter('.invalid-feedback, .alert-danger, .form-error-message')->each(static fn (Crawler $node): string => trim($node->text())));
    }

    private function loginAdmin(): void
    {
        $admin = $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => 'sylius']);
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    private function buyer(string $code): Buyer
    {
        $this->entityManager()->clear();
        $buyer = $this->entityManager()->getRepository(Buyer::class)->findOneBy(['code' => $code]);
        self::assertInstanceOf(Buyer::class, $buyer);

        return $buyer;
    }

    private function customer(): CustomerInterface
    {
        $customer = $this->entityManager()->getRepository(CustomerInterface::class)->findOneBy(['email' => 'shop@example.com']);
        self::assertInstanceOf(CustomerInterface::class, $customer);
        $user = $customer->getUser();
        if ($user instanceof \Sylius\Component\Core\Model\ShopUserInterface && !$user->isVerified()) {
            $user->setVerifiedAt(new \DateTime());
            $this->entityManager()->flush();
        }

        return $customer;
    }

    private function channel(): ChannelInterface
    {
        $channel = $this->entityManager()->getRepository(ChannelInterface::class)->findOneBy([]);
        self::assertInstanceOf(ChannelInterface::class, $channel);

        return $channel;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
