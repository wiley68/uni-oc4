<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** One derived origin identity and conservative handling of historical durable rows. */
final class CpOriginGuard
{
    public const RECONCILIATION_REQUIRED = 'cp_origin_reconciliation_required';

    public static function current(): string
    {
        return (new ModuleDeploymentEnvironment())->controlPanelOrigin();
    }

    public static function matches(mixed $origin): bool
    {
        return is_string($origin) && $origin !== '' && hash_equals(self::current(), $origin);
    }

    /** Origin travels inside the encrypted value, so partial writes cannot relabel old secrets. */
    public static function encodeValue(string $value, ?string $origin = null): string
    {
        $origin ??= self::current();
        if (!self::matches($origin)) {
            throw new CpException('Control Panel deployment changed before state persistence.');
        }
        return json_encode(['cp_origin' => $origin, 'value' => $value], JSON_THROW_ON_ERROR);
    }

    public static function decodeValue(string $plain): ?string
    {
        $envelope = json_decode($plain, true);
        if (!is_array($envelope) || !self::matches($envelope['cp_origin'] ?? null)
            || !is_string($envelope['value'] ?? null) || $envelope['value'] === '') {
            return null;
        }
        return $envelope['value'];
    }

    public static function assertAttempt(array $row): void
    {
        if (!self::matches($row['cp_origin'] ?? null)) {
            throw new ProductFinancingFlowException(
                self::RECONCILIATION_REQUIRED,
                'Поръчката изисква проверка на връзката с Контролния панел преди продължаване.',
                ['recoverable' => '0']
            );
        }
    }

    /** Lazy nullable migration; never assigns today's origin to historical rows. */
    public static function ensureSchema(DbConnection $db): void
    {
        static $ready;
        $ready ??= new \WeakMap();
        if (isset($ready[$db])) {
            return;
        }
        $table = $db->getPrefix() . PersistenceTableNames::FINANCING_ATTEMPT;
        $result = $db->query("SHOW COLUMNS FROM `{$table}` LIKE 'cp_origin'");
        if ((int) ($result->num_rows ?? 0) === 0) {
            try {
                $db->query("ALTER TABLE `{$table}` ADD COLUMN `cp_origin` VARCHAR(320) NULL");
            } catch (\Throwable $exception) {
                $result = $db->query("SHOW COLUMNS FROM `{$table}` LIKE 'cp_origin'");
                if ((int) ($result->num_rows ?? 0) === 0) {
                    throw new PersistenceException('CP origin migration failed.', 0, $exception);
                }
            }
        }
        $ready[$db] = true;
    }
}
