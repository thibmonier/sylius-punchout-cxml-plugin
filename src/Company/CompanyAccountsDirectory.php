<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Company;

use Sylius\Component\Core\Model\CustomerInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Repository\CompanyMemberRepositoryInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Repository\CompanyRepositoryInterface;

/** The companies of tmonier/sylius-company-accounts-plugin (loaded only when the bundle is enabled). */
final readonly class CompanyAccountsDirectory implements CompanyDirectoryInterface
{
    public function __construct(
        private CompanyRepositoryInterface $companyRepository,
        private CompanyMemberRepositoryInterface $memberRepository,
    ) {
    }

    public function companies(): array
    {
        $companies = [];
        foreach ($this->companyRepository->findBy([], ['name' => 'ASC']) as $company) {
            if ($company->getCode() !== null) {
                $companies[$company->getCode()] = $company->getName() ?? $company->getCode();
            }
        }

        return $companies;
    }

    public function isActiveMember(CustomerInterface $customer, string $companyCode): bool
    {
        $member = $this->memberRepository->findOneByCustomer($customer);

        $company = $member?->getCompany();

        return $member instanceof \Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface && $member->isActive() && $company instanceof \Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface && $company->isEnabled() && $company->getCode() === $companyCode;
    }
}
