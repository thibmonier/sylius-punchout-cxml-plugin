<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPunchoutCxmlPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tmonier\SyliusPunchoutCxmlPlugin\Company\CompanyDirectoryInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\DependencyInjection\TmonierSyliusPunchoutCxmlExtension;

/** US-006: the company field exists only with tmonier/sylius-company-accounts-plugin. */
final class CompanyAccountsBridgeTest extends TestCase
{
    public function testWithoutTheCompanyAccountsPluginThereIsNoCompanyDirectory(): void
    {
        self::assertFalse($this->container([])->has(CompanyDirectoryInterface::class));
    }

    public function testWithTheCompanyAccountsPluginBuyersCanBeAttachedToACompany(): void
    {
        $container = $this->container(['TmonierSyliusCompanyAccountsPlugin' => TmonierSyliusPunchoutCxmlExtension::COMPANY_ACCOUNTS_BUNDLE]);

        self::assertTrue($container->has(CompanyDirectoryInterface::class));
    }

    /** @param array<string, string> $bundles */
    private function container(array $bundles): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', $bundles);
        $container->setParameter('kernel.debug', false);
        (new TmonierSyliusPunchoutCxmlExtension())->load([], $container);

        return $container;
    }
}
