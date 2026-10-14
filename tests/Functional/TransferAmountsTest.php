<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Tmonier\SyliusPunchoutCxmlPlugin\TestCatalog;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;

/** US-019: the tax and the shipping of the cart are sent only when the buyer wants them. */
final class TransferAmountsTest extends WebTestCase
{
    use DatabaseTransactionTrait;
    use PunchoutRoundTripTrait;

    public function testNeitherTaxNorShippingByDefault(): void
    {
        $message = $this->transferredMessage(includeTax: false, includeShipping: false);

        self::assertStringNotContainsString('<Tax>', $message);
        self::assertStringNotContainsString('<Shipping>', $message);
        $this->assertValidAgainstTheDtd($message);
    }

    public function testTheTaxOfTheCartWhenTheBuyerWantsIt(): void
    {
        $message = $this->transferredMessage(includeTax: true, includeShipping: false);

        self::assertMatchesRegularExpression('#<Total><Money currency="[A-Z]{3}">100\.00</Money></Total><Tax><Money currency="[A-Z]{3}">20\.00</Money><Description xml:lang="[a-z]{2}[^"]*">[^<]+</Description></Tax>#', $message);
        $this->assertValidAgainstTheDtd($message);
    }

    public function testTheShippingOfTheCartWhenTheBuyerWantsIt(): void
    {
        $message = $this->transferredMessage(includeTax: false, includeShipping: true);

        self::assertMatchesRegularExpression('#<Total><Money currency="[A-Z]{3}">100\.00</Money></Total><Shipping><Money currency="[A-Z]{3}">\d+\.\d{2}</Money><Description xml:lang="[a-z]{2}[^"]*">[^<]+</Description></Shipping>#', $message);
        $this->assertValidAgainstTheDtd($message);
    }

    private function transferredMessage(bool $includeTax, bool $includeShipping): string
    {
        $catalog = new TestCatalog(self::getContainer());
        ['buyer' => $buyer, 'secret' => $secret] = $catalog->createBuyerWithCustomer();
        $channel = $buyer->getChannel();
        self::assertInstanceOf(ChannelInterface::class, $channel);
        $catalog->withoutPromotions($channel);
        $variant = $catalog->createVariant($channel, 10000, $catalog->createTaxCategoryWithRate($channel, 0.2, includedInPrice: false));

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $managed = $em->find(Buyer::class, $buyer->getId());
        self::assertInstanceOf(Buyer::class, $managed);
        $managed->setIncludeTax($includeTax);
        $managed->setIncludeShipping($includeShipping);
        $em->flush();

        $this->startPunchout($buyer, $secret);
        $this->addToCurrentCart((string) $variant->getCode(), 1);

        return $this->transfer();
    }
}
