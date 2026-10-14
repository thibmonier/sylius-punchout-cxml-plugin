<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Tmonier\SyliusPunchoutCxmlPlugin\Company\CompanyAccountsDirectory;
use Tmonier\SyliusPunchoutCxmlPlugin\Company\CompanyDirectoryInterface;

// Loaded by the extension only when TmonierSyliusCompanyAccountsPlugin is enabled (US-006).
return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->set(CompanyAccountsDirectory::class)
        ->arg('$companyRepository', service('tmonier_company_accounts.repository.company'))
        ->arg('$memberRepository', service('tmonier_company_accounts.repository.company_member'));
    $services->alias(CompanyDirectoryInterface::class, CompanyAccountsDirectory::class);
};
