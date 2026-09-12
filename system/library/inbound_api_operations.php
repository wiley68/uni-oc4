<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Signed operation binding for CP→module inbound endpoints.
 */
final class InboundApiOperations
{
    public const SHOP_CACHE = 'shop-cache';

    public const ORDER_BANK_STATUS = 'order-bank-status';

    public const SMARTUCF_DEBUG_LOG = 'smartucf-debug-log';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SHOP_CACHE,
            self::ORDER_BANK_STATUS,
            self::SMARTUCF_DEBUG_LOG,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function assertExact(array $payload, string $expected): void
    {
        $operation = $payload['operation'] ?? null;
        if (!is_string($operation) || $operation === '') {
            throw new ModuleApiException('Полето operation е задължително.', 400, 'unsupported_operation');
        }
        if ($operation !== $expected) {
            throw new ModuleApiException('Неподдържана операция за този endpoint.', 400, 'unsupported_operation');
        }
    }
}
