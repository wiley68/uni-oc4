<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Server-authoritative Process 2 field validation (PS9/Woo parity).
 *
 * EGN: exactly 10 digits; first 8 form a valid YYYYMMDD calendar date.
 * No Bulgarian EGN checksum — matches current reference modules.
 *
 * phone2: charset [-0-9+() ] with at least one digit; no min/max length.
 */
final class ProcessTwoFieldValidator
{
    public const MSG_EGN_REQUIRED = 'Полето е задължително.';
    public const MSG_EGN_INVALID =
        'ЕГН трябва да съдържа 10 цифри. Първите 8 трябва да са валидна дата във формат ГГГГММДД.';
    public const MSG_PHONE2_REQUIRED = 'Полето е задължително.';
    public const MSG_PHONE2_INVALID =
        'Вторият телефон може да съдържа цифри, интервали, +, -, ( и ).';

    public const MSG_EGN_REQUIRED_CHECKOUT = 'Полето „ЕГН“ е задължително.';
    public const MSG_EGN_INVALID_CHECKOUT =
        'Въведете валидно ЕГН (10 цифри, първите 8 — дата YYYYMMDD).';
    public const MSG_PHONE2_REQUIRED_CHECKOUT = 'Полето „Втори телефон“ е задължително.';
    public const MSG_PHONE2_INVALID_CHECKOUT = 'Въведете валиден втори телефонен номер.';

    /**
     * @param array<string, mixed> $posted
     * @return ProcessTwoSensitiveData
     */
    public function validate(array $posted, bool $checkoutCopy = false): ProcessTwoSensitiveData
    {
        $errors = [];
        $egnRaw = (string) ($posted['egn'] ?? '');
        $phone2Raw = (string) ($posted['phone2'] ?? '');

        $egnDigits = preg_replace('/\D+/', '', $egnRaw) ?? '';
        if ($egnDigits === '') {
            $errors['egn'] = $checkoutCopy ? self::MSG_EGN_REQUIRED_CHECKOUT : self::MSG_EGN_REQUIRED;
        } elseif (!$this->isValidEgn($egnDigits)) {
            $errors['egn'] = $checkoutCopy ? self::MSG_EGN_INVALID_CHECKOUT : self::MSG_EGN_INVALID;
        }

        $phone2 = $this->sanitizePhone($phone2Raw);
        if ($phone2 === '') {
            $errors['phone2'] = $checkoutCopy ? self::MSG_PHONE2_REQUIRED_CHECKOUT : self::MSG_PHONE2_REQUIRED;
        } elseif (!$this->isValidPhone($phone2)) {
            $errors['phone2'] = $checkoutCopy ? self::MSG_PHONE2_INVALID_CHECKOUT : self::MSG_PHONE2_INVALID;
        }

        if ($errors !== []) {
            throw new ProductFinancingFlowException('validation', 'Моля, коригирайте данните.', $errors);
        }

        return new ProcessTwoSensitiveData($egnDigits, $phone2);
    }

    public function isValidEgn(string $digits): bool
    {
        if (!preg_match('/^\d{10}$/', $digits)) {
            return false;
        }
        $year = (int) substr($digits, 0, 4);
        $month = (int) substr($digits, 4, 2);
        $day = (int) substr($digits, 6, 2);

        return checkdate($month, $day, $year);
    }

    public function isValidPhone(string $value): bool
    {
        return (bool) preg_match('/^[-0-9+() ]+$/', $value) && (bool) preg_match('/\d/', $value);
    }

    public function sanitizePhone(string $value): string
    {
        $cleaned = preg_replace('/[^0-9+() -]/', '', $value) ?? '';

        return trim($cleaned);
    }
}
