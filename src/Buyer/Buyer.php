<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Buyer;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;

class Buyer implements BuyerInterface, \Stringable
{
    private ?int $id = null;

    private string $code = '';

    private string $name = '';

    private string $protocol = 'cxml';

    private bool $enabled = true;

    private ?CustomerInterface $customer = null;

    private ?ChannelInterface $channel = null;

    private ?string $companyId = null;

    /** @var list<string> */
    private array $returnDomains = [];

    /** @var list<string> */
    private array $iframeOrigins = [];

    private ?string $locale = null;

    private ?bool $taxIncluded = null;

    private ?bool $includeShipping = null;

    /** @var Collection<int, BuyerIdentity> */
    private readonly Collection $identities;

    /** @var Collection<int, BuyerCredential> */
    private readonly Collection $credentials;

    public function __construct()
    {
        $this->identities = new ArrayCollection();
        $this->credentials = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getProtocol(): string
    {
        return $this->protocol;
    }

    public function setProtocol(string $protocol): void
    {
        $this->protocol = $protocol;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getCustomer(): ?CustomerInterface
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerInterface $customer): void
    {
        $this->customer = $customer;
    }

    public function getChannel(): ?ChannelInterface
    {
        return $this->channel;
    }

    public function setChannel(?ChannelInterface $channel): void
    {
        $this->channel = $channel;
    }

    public function getCompanyId(): ?string
    {
        return $this->companyId;
    }

    public function setCompanyId(?string $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function getReturnDomains(): array
    {
        return $this->returnDomains;
    }

    public function setReturnDomains(array $returnDomains): void
    {
        $this->returnDomains = $returnDomains;
    }

    public function getIframeOrigins(): array
    {
        return $this->iframeOrigins;
    }

    public function setIframeOrigins(array $iframeOrigins): void
    {
        $this->iframeOrigins = $iframeOrigins;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): void
    {
        $this->locale = $locale;
    }

    public function getTaxIncluded(): ?bool
    {
        return $this->taxIncluded;
    }

    public function setTaxIncluded(?bool $taxIncluded): void
    {
        $this->taxIncluded = $taxIncluded;
    }

    public function getIncludeShipping(): ?bool
    {
        return $this->includeShipping;
    }

    public function setIncludeShipping(?bool $includeShipping): void
    {
        $this->includeShipping = $includeShipping;
    }

    public function getIdentities(): Collection
    {
        return $this->identities;
    }

    public function addIdentity(BuyerIdentity $identity): void
    {
        if (!$this->identities->contains($identity)) {
            $this->identities->add($identity);
            $identity->setBuyer($this);
        }
    }

    public function removeIdentity(BuyerIdentity $identity): void
    {
        $this->identities->removeElement($identity);
    }

    public function getCredentials(): Collection
    {
        return $this->credentials;
    }

    public function addCredential(BuyerCredential $credential): void
    {
        if (!$this->credentials->contains($credential)) {
            $this->credentials->add($credential);
            $credential->setBuyer($this);
        }
    }

    public function removeCredential(BuyerCredential $credential): void
    {
        $this->credentials->removeElement($credential);
    }

    public function getActiveCredentials(): array
    {
        return array_values(
            $this->credentials->filter(
                static fn (BuyerCredential $c): bool => $c->isActive(),
            )->toArray(),
        );
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : $this->code;
    }
}
