<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Company;

use Sylius\Component\Core\Model\CustomerInterface;

/**
 * The companies a buyer can be attached to (US-006). Implemented for tmonier/sylius-company-accounts-plugin
 * when it is installed; without it the service does not exist and the buyer has no company field.
 */
interface CompanyDirectoryInterface
{
    /** @return array<string, string> company name by company code */
    public function companies(): array;

    public function isActiveMember(CustomerInterface $customer, string $companyCode): bool;
}
