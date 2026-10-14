<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Unit\Doctor;

use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUser;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\Buyer;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerCredential;
use Tmonier\SyliusPunchoutCxmlPlugin\Buyer\BuyerIdentity;
use Tmonier\SyliusPunchoutCxmlPlugin\Company\CompanyDirectoryInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Doctor\BuyerChecker;

final class BuyerCheckerTest extends TestCase
{
    public function testACompleteCxmlBuyerHasNoProblem(): void
    {
        self::assertSame([], (new BuyerChecker())->problems($this->buyer()));
    }

    public function testAnEmptyBuyerListsEverythingMissing(): void
    {
        self::assertSame([
            'no allowed return domain: every return URL is refused',
            'no active shared secret',
            'no From identity',
            'no Sender identity',
            'no enabled channel',
            'no Sylius customer',
        ], (new BuyerChecker())->problems(new Buyer()));
    }

    public function testAnOciBuyerNeedsAUsernameButNoIdentity(): void
    {
        $buyer = $this->buyer(identities: false);
        $buyer->setProtocol('oci');

        self::assertSame(['no OCI username'], (new BuyerChecker())->problems($buyer));
        $buyer->setOciUsername('SAP_SRM');
        self::assertSame([], (new BuyerChecker())->problems($buyer));
    }

    public function testTheShopAccountMustBeVerifiedWhenTheChannelRequiresIt(): void
    {
        $buyer = $this->buyer();
        $buyer->getCustomer()?->getUser()?->setVerifiedAt(null);

        self::assertSame(['the shop account of the customer is not verified (required by the channel)'], (new BuyerChecker())->problems($buyer));
    }

    public function testTheCustomerMustStillBelongToTheCompanyOfTheBuyer(): void
    {
        $directory = new class() implements CompanyDirectoryInterface {
            public function companies(): array
            {
                return ['bigcorp' => 'BigCorp'];
            }

            public function isActiveMember(CustomerInterface $customer, string $companyCode): bool
            {
                return false;
            }
        };
        $buyer = $this->buyer();
        $buyer->setCompanyId('bigcorp');

        self::assertSame(['the Sylius customer is not an active member of the company "bigcorp"'], (new BuyerChecker($directory))->problems($buyer));
        self::assertSame([], (new BuyerChecker())->problems($buyer), 'Without company accounts the company is not checked');
    }

    private function buyer(bool $identities = true): Buyer
    {
        $buyer = new Buyer();
        $buyer->setReturnDomains(['ariba.example.com']);
        $credential = new BuyerCredential();
        $credential->setPasswordHash('hash');
        $buyer->addCredential($credential);
        if ($identities) {
            foreach ([BuyerIdentity::ROLE_FROM, BuyerIdentity::ROLE_SENDER] as $role) {
                $identity = new BuyerIdentity();
                $identity->setRole($role);
                $buyer->addIdentity($identity);
            }
        }
        $channel = new Channel();
        $channel->setEnabled(true);
        $channel->setAccountVerificationRequired(true);
        $buyer->setChannel($channel);
        $user = new ShopUser();
        $user->setEnabled(true);
        $user->setVerifiedAt(new \DateTime());
        $customer = new Customer();
        $customer->setUser($user);
        $buyer->setCustomer($customer);

        return $buyer;
    }
}
