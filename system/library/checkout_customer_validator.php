<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Checkout Phase 9 customer validation.
 *
 * Mirrors Product identity rules for firstname/lastname/email, but primary telephone
 * is optional (native present → use it; missing → ''). Product/Cart keep
 * {@see ProductCustomerValidator} requiring telephone.
 */
final class CheckoutCustomerValidator
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
        // Primary telephone optional for Checkout (legacy Process 1 / uni-oc4-old).
        if ($telephone !== '' && mb_strlen($telephone) > 32) {
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
