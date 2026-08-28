<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

/**
 * Local encoding of Control Panel POST /api/v1/orders validation.
 * Not a production client. Rules copied from StoreOrderRequest.
 */
final class CpOrderPayloadConstraints
{
    public const ORDER_ID_MAX = 13;

    public const NAME_MAX = 65;

    public const PHONE_MAX = 45;

    public const PHONE_PATTERN = '/^[\d\s\-\+\(\)]+$/';

    public const EMAIL_MAX = 128;

    public const ADDRESS_MAX = 256;

    public const VNOSKI_MIN = 1;

    public const VNOSKI_MAX = 255;

    public const VNOSKI_DEFAULT = 12;

    public const PARVA_DEFAULT = 0.0;

    public const TYPE_CLIENT_MAX = 255;

    public const TYPE_CLIENT_DEFAULT = 0;

    public const CURRENCY_MAX = 3;

    /** @var list<string> */
    public const CURRENCIES = ['BGN', 'EUR'];

    public const CURRENCY_DEFAULT = 'BGN';

    public const VERSION_MAX = 11;

    public const VERSION_PATTERN = '/^\d{1,3}\.\d{1,3}\.\d{1,3}$/';

    public const STATUS_MAX = 255;

    public const DEFAULT_STATUS = 'Създаден в КП Банка';

    public const DEFAULT_STATUS_ID = 'cp_sent';

    /** @var list<string> */
    public const REQUIRED = [
        'order_id',
        'name',
        'phone',
        'email',
        'address',
        'price',
        'vnoska',
        'gpr',
    ];

    /** @var list<string> */
    public const CREATE_FIELDS = [
        'order_id',
        'name',
        'phone',
        'email',
        'address',
        'address2',
        'price',
        'vnoska',
        'gpr',
        'vnoski',
        'parva',
        'products_id',
        'products_name',
        'products_q',
        'type_client',
        'currency',
        'version',
        'status',
        'status_id',
    ];

    /** @var list<string> */
    public const IDEMPOTENCY_SEMANTIC_FIELDS = [
        'name',
        'phone',
        'email',
        'address',
        'address2',
        'price',
        'vnoska',
        'gpr',
        'vnoski',
        'parva',
        'status',
        'status_id',
        'products_id',
        'products_name',
        'products_q',
        'type_client',
        'currency',
        'version',
    ];

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    public static function violations(array $payload): array
    {
        $errors = [];

        foreach (self::REQUIRED as $field) {
            if (!array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
                $errors[] = $field . ' is required';
            }
        }

        self::stringMax($errors, $payload, 'order_id', self::ORDER_ID_MAX);
        self::stringMax($errors, $payload, 'name', self::NAME_MAX);
        self::stringMax($errors, $payload, 'phone', self::PHONE_MAX);
        self::stringMax($errors, $payload, 'email', self::EMAIL_MAX);
        self::stringMax($errors, $payload, 'address', self::ADDRESS_MAX);
        self::stringMax($errors, $payload, 'address2', self::ADDRESS_MAX, true);
        self::stringMax($errors, $payload, 'status', self::STATUS_MAX, true);
        self::stringMax($errors, $payload, 'status_id', self::STATUS_MAX, true);
        self::stringMax($errors, $payload, 'currency', self::CURRENCY_MAX, true);
        self::stringMax($errors, $payload, 'version', self::VERSION_MAX, true);

        if (
            isset($payload['phone']) && is_string($payload['phone']) && $payload['phone'] !== ''
            && preg_match(self::PHONE_PATTERN, $payload['phone']) !== 1
        ) {
            $errors[] = 'phone fails regex';
        }

        if (
            isset($payload['email']) && is_string($payload['email']) && $payload['email'] !== ''
            && filter_var($payload['email'], FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors[] = 'email is invalid';
        }

        foreach (['price', 'vnoska', 'gpr'] as $numeric) {
            if (array_key_exists($numeric, $payload) && (!is_numeric($payload[$numeric]) || (float) $payload[$numeric] < 0)) {
                $errors[] = $numeric . ' must be numeric >= 0';
            }
        }

        if (
            array_key_exists('parva', $payload) && $payload['parva'] !== null
            && (!is_numeric($payload['parva']) || (float) $payload['parva'] < 0)
        ) {
            $errors[] = 'parva must be numeric >= 0';
        }

        if (array_key_exists('vnoski', $payload) && $payload['vnoski'] !== null) {
            $months = (int) $payload['vnoski'];
            if (!is_numeric($payload['vnoski']) || $months < self::VNOSKI_MIN || $months > self::VNOSKI_MAX) {
                $errors[] = 'vnoski must be integer 1-255';
            }
        }

        if (array_key_exists('type_client', $payload) && $payload['type_client'] !== null) {
            $type = (int) $payload['type_client'];
            if (!is_numeric($payload['type_client']) || $type < 0 || $type > self::TYPE_CLIENT_MAX) {
                $errors[] = 'type_client must be integer 0-255';
            }
        }

        if (
            isset($payload['currency']) && is_string($payload['currency']) && $payload['currency'] !== ''
            && !in_array($payload['currency'], self::CURRENCIES, true)
        ) {
            $errors[] = 'currency must be BGN or EUR';
        }

        if (
            isset($payload['version']) && is_string($payload['version']) && $payload['version'] !== ''
            && preg_match(self::VERSION_PATTERN, $payload['version']) !== 1
        ) {
            $errors[] = 'version must match x.x.x';
        }

        return $errors;
    }

    /**
     * @param list<string> $errors
     * @param array<string, mixed> $payload
     */
    private static function stringMax(array &$errors, array $payload, string $field, int $max, bool $optional = false): void
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) {
            return;
        }

        if (!is_string($payload[$field])) {
            $errors[] = $field . ' must be string';

            return;
        }

        if ($optional && $payload[$field] === '') {
            return;
        }

        if (strlen($payload[$field]) > $max) {
            $errors[] = $field . ' exceeds max ' . $max;
        }
    }
}
