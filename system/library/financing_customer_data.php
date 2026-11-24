<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Normalized customer data required for OpenCart order creation.
 *
 * Retired Process 2 fields are excluded.
 */
final class FinancingCustomerData
{
    public int $customerId;

    public int $customerGroupId;

    public string $firstname;

    public string $lastname;

    public string $email;

    public string $telephone;

    /** @var array<string, mixed> */
    public array $customField;

    /**
     * @param array<string, mixed> $customField
     */
    public function __construct(
        int $customerId,
        int $customerGroupId,
        string $firstname,
        string $lastname,
        string $email,
        string $telephone,
        array $customField = []
    ) {
        $this->customerId = $customerId;
        $this->customerGroupId = $customerGroupId;
        $this->firstname = $firstname;
        $this->lastname = $lastname;
        $this->email = $email;
        $this->telephone = $telephone;
        $this->customField = $customField;
    }
}
