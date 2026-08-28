<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Thrown when a shop configuration snapshot fails canonical structural validation.
 * Must not purge known-good cache on pull.
 */
final class ShopSnapshotValidationException extends \RuntimeException
{
    public const ERROR_CODE = 'shop_snapshot_invalid';

    /** @var list<array{path: string, code: string}> */
    private array $violations;

    /**
     * @param list<array{path: string, code: string}> $violations
     */
    public function __construct(array $violations, string $message = 'Конфигурацията на магазина е невалидна.')
    {
        parent::__construct($message);
        $this->violations = array_values($violations);
    }

    /** @return list<array{path: string, code: string}> */
    public function violations(): array
    {
        return $this->violations;
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }
}
