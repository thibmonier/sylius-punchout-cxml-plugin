<?php

declare(strict_types=1);

namespace Tmonier\SyliusPunchoutCxmlPlugin\Order;

use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusPunchoutCxmlPlugin\Cxml\CxmlAddress;

/**
 * A Sylius address from a cXML Address (ADR-0008): Name is the company, DeliverTo the first and last
 * names (the company name when there is no DeliverTo). Street, city, postcode and country are required.
 */
final readonly class AddressFactory
{
    /** @param FactoryInterface<AddressInterface> $addressFactory */
    public function __construct(private FactoryInterface $addressFactory)
    {
    }

    public function create(CxmlAddress $source, string $role): AddressInterface
    {
        if ($source->street === [] || $source->city === '' || $source->postalCode === '' || 1 !== preg_match('/^[A-Z]{2}$/', $source->countryCode)) {
            throw new OrderRequestRejected(sprintf('%s address incomplete (street, city, postal code and country are required)', $role));
        }
        $person = $source->deliverTo !== '' ? $source->deliverTo : $source->name;
        $names = preg_split('/\s+/', trim($person), 2) ?: [];

        $address = $this->addressFactory->createNew();
        $address->setFirstName(mb_substr($names[0] ?? '-', 0, 255));
        $address->setLastName(mb_substr($names[1] ?? ($names[0] ?? '-'), 0, 255));
        $address->setCompany($source->name !== '' ? mb_substr($source->name, 0, 255) : null);
        $address->setStreet(mb_substr(implode(', ', $source->street), 0, 255));
        $address->setCity(mb_substr($source->city, 0, 255));
        $address->setPostcode(mb_substr($source->postalCode, 0, 255));
        $address->setCountryCode($source->countryCode);
        $address->setProvinceName($source->state !== null ? mb_substr($source->state, 0, 255) : null);

        return $address;
    }
}
