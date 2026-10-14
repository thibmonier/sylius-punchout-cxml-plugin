<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Buyer;

use Doctrine\Common\Collections\Collection;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Resource\Model\ResourceInterface;

interface BuyerInterface extends ResourceInterface
{
    public function getId(): ?int;

    public function getCode(): string;

    public function setCode(string $code): void;

    public function getName(): string;

    public function setName(string $name): void;

    public function getProtocol(): string;

    public function setProtocol(string $protocol): void;

    public function isEnabled(): bool;

    public function setEnabled(bool $enabled): void;

    public function getCustomer(): ?CustomerInterface;

    public function setCustomer(?CustomerInterface $customer): void;

    public function getChannel(): ?ChannelInterface;

    public function setChannel(?ChannelInterface $channel): void;

    public function getCompanyId(): ?string;

    public function setCompanyId(?string $companyId): void;

    /** @return list<string> */
    public function getReturnDomains(): array;

    /** @param list<string> $returnDomains */
    public function setReturnDomains(array $returnDomains): void;

    /** @return list<string> */
    public function getIframeOrigins(): array;

    /** @param list<string> $iframeOrigins */
    public function setIframeOrigins(array $iframeOrigins): void;

    public function getLocale(): ?string;

    public function setLocale(?string $locale): void;

    public function getIncludeShipping(): ?bool;

    public function setIncludeShipping(?bool $includeShipping): void;

    /** @return Collection<int, BuyerIdentity> */
    public function getIdentities(): Collection;

    public function addIdentity(BuyerIdentity $identity): void;

    public function removeIdentity(BuyerIdentity $identity): void;

    /** @return Collection<int, BuyerCredential> */
    public function getCredentials(): Collection;

    public function addCredential(BuyerCredential $credential): void;

    public function removeCredential(BuyerCredential $credential): void;

    /** @return list<BuyerCredential> */
    public function getActiveCredentials(): array;
}
