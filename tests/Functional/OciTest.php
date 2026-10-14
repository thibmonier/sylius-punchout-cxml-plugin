<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Controller\OciEntryAction;
use Tmonier\SyliusPunchoutCxmlPlugin\Log\MessageLog;

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
        self::assertSame([
            'NEW_ITEM-DESCRIPTION[1]' => mb_substr((string) $variant->getProduct()?->getName(), 0, 40),
            'NEW_ITEM-QUANTITY[1]' => '2',
            'NEW_ITEM-UNIT[1]' => 'EA',
            'NEW_ITEM-PRICE[1]' => '254.40',
            'NEW_ITEM-CURRENCY[1]' => (string) $channel->getBaseCurrency()?->getCode(),
            'NEW_ITEM-PRICEUNIT[1]' => '1',
            'NEW_ITEM-VENDORMAT[1]' => (string) $variant->getCode(),
            'NEW_ITEM-EXT_PRODUCT_ID[1]' => 'v1:' . $variant->getCode(),
            'NEW_ITEM-LONGTEXT_1:132[]' => (string) $variant->getProduct()?->getName(),
            // Symfony sorts the query string of a GET entry.
            '~CALLER' => 'CTLG',
            '~OkCode' => 'ADDI',
            '~TARGET' => '_top',
        ], $fields);
        $form = $this->client->getCrawler()->filter('form');
        self::assertSame(self::HOOK, $form->attr('action'));
        self::assertSame('_top', $form->attr('target'));
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
        $this->enter($buyer, ['USERNAME' => $username, 'PASSWORD' => $password, 'HOOK_URL' => self::HOOK, 'FUNCTION' => 'DETAIL']);
        self::assertResponseStatusCodeSame(400, 'OCI functions come with v1.1');
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

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param array<string, string> $parameters */
    private function enter(Buyer $buyer, array $parameters, string $method = 'GET'): void
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
