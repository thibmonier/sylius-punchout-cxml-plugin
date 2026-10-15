<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\Company;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMember;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Controller\OciEntryAction;
use Tmonier\SyliusPunchoutCxmlPlugin\Log\MessageLog;
use Tmonier\SyliusPunchoutCxmlPlugin\Session\PunchoutSession;

/** US-003 (OCI credentials), US-012 (OCI entry) and US-020 (OCI return) for SAP SRM / S4. */
final class OciTest extends WebTestCase
{
    use DatabaseTransactionTrait;
    use PunchoutRoundTripTrait;

    private const HOOK = 'https://sap.bigcorp.example/sap/bc/srm/hook';

    public function testSapOpensTheCatalogAndGetsTheCartBackInOciFields(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'username' => $username, 'password' => $password] = $catalog->createOciBuyer();
        $channel = $buyer->getChannel();
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $catalog->withoutPromotions($channel);
        $variant = $catalog->createVariant($channel, 25440);

        $this->enter($buyer, ['USERNAME' => $username, 'PASSWORD' => $password, 'HOOK_URL' => self::HOOK, '~OkCode' => 'ADDI', '~TARGET' => '_top', '~CALLER' => 'CTLG']);
        self::assertResponseRedirects('/en_US/', 303);
        $this->addToCurrentCart((string) $variant->getCode(), 2);

        $fields = $this->returnedFields(['_csrf_token' => $this->getCsrfToken('tmonier_punchout_transfer')], '/punchout/transfer');
        // SAP reads the fields by name; the order of the ~ fields follows the JSON storage of the database.
        ksort($fields);
        $expected = [
            'NEW_ITEM-DESCRIPTION[1]' => mb_substr((string) $variant->getProduct()?->getName(), 0, 40),
            'NEW_ITEM-QUANTITY[1]' => '2',
            'NEW_ITEM-UNIT[1]' => 'EA',
            'NEW_ITEM-PRICE[1]' => '254.40',
            'NEW_ITEM-CURRENCY[1]' => (string) $channel->getBaseCurrency()?->getCode(),
            'NEW_ITEM-PRICEUNIT[1]' => '1',
            'NEW_ITEM-VENDORMAT[1]' => (string) $variant->getCode(),
            'NEW_ITEM-EXT_PRODUCT_ID[1]' => 'v1:' . $variant->getCode(),
            'NEW_ITEM-LONGTEXT_1:132[]' => (string) $variant->getProduct()?->getName(),
            '~CALLER' => 'CTLG',
            '~OkCode' => 'ADDI',
            '~TARGET' => '_top',
        ];
        ksort($expected);
        self::assertSame($expected, $fields);
        $form = $this->client->getCrawler()->filter('form');
        self::assertSame(self::HOOK, $form->attr('action'));
        self::assertSame('_top', $form->attr('target'));
    }

    public function testValidateSendsTheItemBackAtTheCurrentPriceWithoutOpeningASession(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'username' => $username, 'password' => $password] = $catalog->createOciBuyer();
        $channel = $buyer->getChannel();
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $catalog->withoutPromotions($channel);
        $variant = $catalog->createVariant($channel, 4200);

        $this->enter($buyer, ['USERNAME' => $username, 'PASSWORD' => $password, 'HOOK_URL' => self::HOOK, 'FUNCTION' => 'VALIDATE', 'PRODUCTID' => 'v1:' . $variant->getCode(), 'QUANTITY' => '3', '~OkCode' => 'ADDI']);

        self::assertResponseIsSuccessful();
        $fields = $this->hiddenFields();
        self::assertSame(['42.00', '3', 'v1:' . $variant->getCode(), 'ADDI'], [$fields['NEW_ITEM-PRICE[1]'] ?? null, $fields['NEW_ITEM-QUANTITY[1]'] ?? null, $fields['NEW_ITEM-EXT_PRODUCT_ID[1]'] ?? null, $fields['~OkCode'] ?? null]);
        self::assertSame(self::HOOK, $this->client->getCrawler()->filter('form')->attr('action'));
        self::assertSame(0, $this->entityManager()->getRepository(PunchoutSession::class)->count(['buyer' => $buyer]), 'VALIDATE opens no session');
    }

    public function testValidateOfAnItemNoLongerSoldSendsNoItem(): void
    {
        ['buyer' => $buyer, 'username' => $username, 'password' => $password] = (new TestCatalog(self::getContainer()))->createOciBuyer();

        $this->enter($buyer, ['USERNAME' => $username, 'PASSWORD' => $password, 'HOOK_URL' => self::HOOK, 'FUNCTION' => 'VALIDATE', 'PRODUCTID' => 'v1:UNKNOWN']);

        self::assertResponseIsSuccessful();
        self::assertSame([], array_filter(array_keys($this->hiddenFields()), static fn (string $name): bool => str_starts_with($name, 'NEW_ITEM')));
    }

    public function testDetailOpensTheProductPageInAPunchoutSession(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'username' => $username, 'password' => $password] = $catalog->createOciBuyer();
        $channel = $buyer->getChannel();
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $variant = $catalog->createVariant($channel, 1000);

        $this->enter($buyer, ['USERNAME' => $username, 'PASSWORD' => $password, 'HOOK_URL' => self::HOOK, 'FUNCTION' => 'DETAIL', 'PRODUCTID' => 'v1:' . $variant->getCode()]);

        self::assertResponseRedirects('/en_US/products/' . $variant->getProduct()?->getSlug(), 303);
        self::assertSame(1, $this->entityManager()->getRepository(PunchoutSession::class)->count(['buyer' => $buyer, 'status' => PunchoutSession::STATUS_ACTIVE]));
    }

    public function testAGetEntryIsRefusedByDefault(): void
    {
        ['buyer' => $buyer, 'username' => $username, 'password' => $password] = (new TestCatalog(self::getContainer()))->createOciBuyer();

        $this->enter($buyer, ['USERNAME' => $username, 'PASSWORD' => $password, 'HOOK_URL' => self::HOOK], 'GET');

        self::assertResponseStatusCodeSame(405, 'The password would end up in access logs');
    }

    public function testASpentIdentityBudgetStillLetsTheRightCredentialsIn(): void
    {
        ['buyer' => $buyer, 'username' => $username, 'password' => $password] = (new TestCatalog(self::getContainer()))->createOciBuyer();
        $limiter = self::getContainer()->get('tmonier_punchout.test.identity_failure_limiter');
        self::assertInstanceOf(RateLimiterFactory::class, $limiter);
        $limiter->create('oci|' . $buyer->getCode())->consume(50);

        $this->enter($buyer, ['USERNAME' => $username, 'PASSWORD' => 'wrong-' . bin2hex(random_bytes(8)), 'HOOK_URL' => self::HOOK]);
        self::assertResponseStatusCodeSame(429);
        $this->enter($buyer, ['USERNAME' => $username, 'PASSWORD' => $password, 'HOOK_URL' => self::HOOK]);
        self::assertResponseRedirects('/en_US/', 303, 'Failures sent by others do not lock the buyer out');
    }

    public function testACustomerWhoLeftTheCompanyOfTheBuyerOpensNoSession(): void
    {
        ['buyer' => $buyer, 'username' => $username, 'password' => $password] = (new TestCatalog(self::getContainer()))->createOciBuyer();
        $company = new Company();
        $company->setCode('punchout-left-' . bin2hex(random_bytes(3)));
        $company->setName('BigCorp');
        $company->setEnabled(true);
        $member = new CompanyMember();
        $member->setCompany($company);
        $member->setCustomer($buyer->getCustomer());
        $member->setEnabled(false);
        $buyer->setCompanyId($company->getCode());
        $em = $this->entityManager();
        $em->persist($company);
        $em->persist($member);
        $em->flush();

        $this->enter($buyer, ['USERNAME' => $username, 'PASSWORD' => $password, 'HOOK_URL' => self::HOOK]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheEntryAcceptsAPostAndTheReturnTargetOfSap(): void
    {
        ['buyer' => $buyer, 'username' => $username, 'password' => $password] = (new TestCatalog(self::getContainer()))->createOciBuyer();

        $this->enter($buyer, ['USERNAME' => $username, 'PASSWORD' => $password, 'HOOK_URL' => self::HOOK, 'returntarget' => '_parent'], 'POST');
        self::assertResponseRedirects('/en_US/', 303);

        $fields = $this->returnedFields(['_csrf_token' => $this->getCsrfToken('tmonier_punchout_cancel')], '/punchout/cancel');
        self::assertSame([], $fields, 'Cancelling returns no item');
        self::assertSame('_parent', $this->client->getCrawler()->filter('form')->attr('target'));
    }

    public function testWrongCredentialsOrAForeignHookUrlOpenNoSession(): void
    {
        ['buyer' => $buyer, 'username' => $username, 'password' => $password] = (new TestCatalog(self::getContainer()))->createOciBuyer();

        $this->enter($buyer, ['USERNAME' => $username, 'PASSWORD' => 'wrong-' . bin2hex(random_bytes(8)), 'HOOK_URL' => self::HOOK]);
        self::assertResponseStatusCodeSame(403);
        $this->enter($buyer, ['USERNAME' => 'other', 'PASSWORD' => $password, 'HOOK_URL' => self::HOOK]);
        self::assertResponseStatusCodeSame(403);
        $this->enter($buyer, ['USERNAME' => $username, 'PASSWORD' => $password, 'HOOK_URL' => 'https://attacker.example/hook']);
        self::assertResponseStatusCodeSame(403);
        $this->enter($buyer, ['USERNAME' => $username, 'PASSWORD' => $password, 'HOOK_URL' => self::HOOK, 'FUNCTION' => 'SOURCING']);
        self::assertResponseStatusCodeSame(400, 'Only the VALIDATE and DETAIL functions are supported');
    }

    public function testLongDescriptionsAreCutForSapAndSentInFullAsLongText(): void
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'username' => $username, 'password' => $password] = $catalog->createOciBuyer();
        $channel = $buyer->getChannel();
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $variant = $catalog->createVariant($channel, 1000);
        $product = $variant->getProduct();
        self::assertNotNull($product);
        $longName = 'Chaise ergonomique à roulettes réglable en hauteur, accoudoirs 4D';
        $product->setName($longName);
        $this->entityManager()->flush();

        $this->enter($buyer, ['USERNAME' => $username, 'PASSWORD' => $password, 'HOOK_URL' => self::HOOK]);
        $this->addToCurrentCart((string) $variant->getCode(), 1);
        $fields = $this->returnedFields(['_csrf_token' => $this->getCsrfToken('tmonier_punchout_transfer')], '/punchout/transfer');

        self::assertSame(mb_substr($longName, 0, 40), $fields['NEW_ITEM-DESCRIPTION[1]']);
        self::assertSame($longName, $fields['NEW_ITEM-LONGTEXT_1:132[]']);
    }

    public function testTheEntryIsLoggedWithoutThePassword(): void
    {
        ['buyer' => $buyer, 'username' => $username, 'password' => $password] = (new TestCatalog(self::getContainer()))->createOciBuyer();

        $this->enter($buyer, ['USERNAME' => $username, 'PASSWORD' => $password, 'HOOK_URL' => self::HOOK]);

        $log = $this->entityManager()->getRepository(MessageLog::class)->findOneBy(['messageType' => OciEntryAction::MESSAGE_TYPE, 'buyer' => $buyer->getId()]);
        self::assertInstanceOf(MessageLog::class, $log);
        self::assertSame([MessageLog::PROTOCOL_OCI, 200], [$log->getProtocol(), $log->getStatus()]);
        self::assertStringContainsString('PASSWORD=***', (string) $log->getMaskedBody());
        self::assertStringNotContainsString($password, (string) $log->getMaskedBody());
    }

    public function testACxmlBuyerCannotEnterThroughOci(): void
    {
        ['buyer' => $buyer, 'username' => $username, 'password' => $password] = (new TestCatalog(self::getContainer()))->createOciBuyer();
        $buyer->setProtocol('cxml');
        $this->entityManager()->flush();

        $this->enter($buyer, ['USERNAME' => $username, 'PASSWORD' => $password, 'HOOK_URL' => self::HOOK]);
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array<string, string> */
    private function hiddenFields(): array
    {
        $fields = [];
        $this->client->getCrawler()->filter('form input[type="hidden"]')->each(static function (Crawler $input) use (&$fields): void {
            $fields[(string) $input->attr('name')] = (string) $input->attr('value');
        });

        return $fields;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param array<string, string> $parameters */
    private function enter(Buyer $buyer, array $parameters, string $method = 'POST'): void
    {
        $this->client->request($method, '/punchout/oci/' . $buyer->getCode(), $parameters, [], ['REMOTE_ADDR' => '203.0.113.10']);
    }

    /**
     * Posts the transfer (or cancel) form and returns the hidden fields of the page sent back to SAP.
     *
     * @param array<string, string> $parameters
     *
     * @return array<string, string>
     */
    private function returnedFields(array $parameters, string $path): array
    {
        $this->client->request('POST', $path, $parameters);
        self::assertResponseIsSuccessful();
        $fields = [];
        $this->client->getCrawler()->filter('form input[type="hidden"]')->each(static function (Crawler $input) use (&$fields): void {
            $fields[(string) $input->attr('name')] = (string) $input->attr('value');
        });

        return $fields;
    }
}
