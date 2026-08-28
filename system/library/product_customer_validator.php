<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class ProductCustomerValidator
{
    /**
     * @param array<string, mixed> $posted
     * @return array{customer: FinancingCustomerData, raw: array<string, string>}
     */
    public function validate(array $posted, int $customerGroupId, int $customerId = 0): array
    {
        $errors = [];
        $firstname = $this->cleanName((string) ($posted['firstname'] ?? ''));
        $lastname = $this->cleanName((string) ($posted['lastname'] ?? ''));
        $email = trim((string) ($posted['email'] ?? ''));
        $telephone = trim((string) ($posted['telephone'] ?? ''));

        if ($firstname === '' || mb_strlen($firstname) > 32) {
            $errors['firstname'] = 'Моля, въведете валидно име.';
        }
        if ($lastname === '' || mb_strlen($lastname) > 32) {
            $errors['lastname'] = 'Моля, въведете валидна фамилия.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 96) {
            $errors['email'] = 'Моля, въведете валиден имейл.';
        }
        if ($telephone === '' || mb_strlen($telephone) > 32) {
            $errors['telephone'] = 'Моля, въведете валиден телефон.';
        }

        if ($errors !== []) {
            throw new ProductFinancingFlowException('validation', 'Моля, коригирайте данните.', $errors);
        }

        $customer = new FinancingCustomerData(
            max(0, $customerId),
            max(0, $customerGroupId),
            $firstname,
            $lastname,
            $email,
            $telephone
        );

        return [
            'customer' => $customer,
            'raw'      => [
                'firstname' => $firstname,
                'lastname'  => $lastname,
                'email'     => $email,
                'telephone' => $telephone,
            ],
        ];
    }

    private function cleanName(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value;
    }
}
