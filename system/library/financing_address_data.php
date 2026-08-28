<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Normalized billing/shipping address for OpenCart order creation. */
final class FinancingAddressData
{
    public int $addressId;

    public string $firstname;

    public string $lastname;

    public string $company;

    public string $address1;

    public string $address2;

    public string $city;

    public string $postcode;

    public string $country;

    public int $countryId;

    public string $zone;

    public int $zoneId;

    public string $addressFormat;

    /** @var array<string, mixed> */
    public array $customField;

    /**
     * @param array<string, mixed> $customField
     */
    public function __construct(
        int $addressId,
        string $firstname,
        string $lastname,
        string $company,
        string $address1,
        string $address2,
        string $city,
        string $postcode,
        string $country,
        int $countryId,
        string $zone,
        int $zoneId,
        string $addressFormat = '',
        array $customField = []
    ) {
        $this->addressId = $addressId;
        $this->firstname = $firstname;
        $this->lastname = $lastname;
        $this->company = $company;
        $this->address1 = $address1;
        $this->address2 = $address2;
        $this->city = $city;
        $this->postcode = $postcode;
        $this->country = $country;
        $this->countryId = $countryId;
        $this->zone = $zone;
        $this->zoneId = $zoneId;
        $this->addressFormat = $addressFormat;
        $this->customField = $customField;
    }
}
