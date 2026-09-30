<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use Opencart\System\Library\Extension\MtUniCredit\DbConnection;
use Opencart\System\Library\Extension\MtUniCredit\MysqliDbConnection;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceTableNames;

/**
 * Optional real-MySQL harness — uses isolated table prefix, never production rows.
 */
final class PersistenceIntegrationHarness
{
    /** OpenCart default store (explicit scope, not "missing"). */
    public const TEST_STORE_ID_DEFAULT = 0;

    /** Additional multistore id used for isolation checks against default. */
    public const TEST_STORE_ID_ONE = 1;

    public const TEST_STORE_ID = 900001;
    public const TEST_STORE_ID_B = 900002;
    public const TEST_UNICID = 'test-unicid-phase3';
    public const TEST_UNICID_B = 'test-unicid-phase3-b';

    /** Required substring in the integration DB prefix (never production `mt_uni_credit` alone). */
    public const INTEGRATION_PREFIX_MARKER = 'mtuni_it';

    private static ?DbConnection $connection = null;

    private static bool $shutdownRegistered = false;

    private static string $activePrefix = '';

    public static function enabled(): bool
    {
        return getenv('MT_UNI_CREDIT_INTEGRATION') === '1';
    }

    public static function connection(): DbConnection
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        if (!self::enabled()) {
            throw new \PHPUnit\Framework\SkippedWithMessageException(
                'Set MT_UNI_CREDIT_INTEGRATION=1 for DB integration tests.'
            );
        }

        $config = self::loadDatabaseConfig();
        self::assertSafeIntegrationPrefix($config['prefix']);

        $mysqli = new \mysqli(
            $config['hostname'],
            $config['username'],
            $config['password'],
            $config['database'],
            $config['port']
        );
        if ($mysqli->connect_errno) {
            throw new \RuntimeException('Integration DB connection failed.');
        }
        $mysqli->set_charset('utf8mb4');

        self::$connection = new MysqliDbConnection($mysqli, $config['prefix']);
        self::$activePrefix = $config['prefix'];
        (new PersistenceSchemaInstaller(self::$connection))->installAll();
        self::ensureNativeProofTables(self::$connection);
        self::registerShutdownCleanup();

        return self::$connection;
    }

    public static function resetTables(): void
    {
        if (!self::enabled()) {
            throw new \PHPUnit\Framework\SkippedWithMessageException(
                'Set MT_UNI_CREDIT_INTEGRATION=1 for DB integration tests.'
            );
        }

        $db = self::connection();
        // Idempotent: recreate if a prior teardown/drop removed tables mid-suite.
        (new PersistenceSchemaInstaller($db))->installAll();
        self::ensureNativeProofTables($db);
        $db->query('TRUNCATE TABLE `' . $db->getPrefix() . 'order_product`');
        $db->query('TRUNCATE TABLE `' . $db->getPrefix() . 'order`');
        foreach (PersistenceTableNames::allPersistenceTables() as $table) {
            $db->query('TRUNCATE TABLE `' . $db->getPrefix() . $table . '`');
        }
    }

    /** Native proof fixtures use only the isolated integration prefix. */
    private static function ensureNativeProofTables(DbConnection $db): void
    {
        $prefix = $db->getPrefix();
        self::assertSafeIntegrationPrefix($prefix);
        $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}order` (
            `order_id` INT NOT NULL PRIMARY KEY,
            `store_id` INT NOT NULL,
            `total` DECIMAL(15,4) NOT NULL,
            `currency_code` VARCHAR(3) NOT NULL,
            `currency_id` INT NOT NULL,
            `currency_value` DECIMAL(15,8) NOT NULL,
            `customer_id` INT NOT NULL DEFAULT 0,
            `customer_group_id` INT NOT NULL DEFAULT 0,
            `firstname` VARCHAR(64) NOT NULL DEFAULT '',
            `lastname` VARCHAR(64) NOT NULL DEFAULT '',
            `email` VARCHAR(128) NOT NULL DEFAULT '',
            `telephone` VARCHAR(45) NOT NULL DEFAULT '',
            `payment_company` VARCHAR(128) NOT NULL DEFAULT '',
            `payment_address_1` VARCHAR(128) NOT NULL DEFAULT '',
            `payment_address_2` VARCHAR(128) NOT NULL DEFAULT '',
            `payment_city` VARCHAR(128) NOT NULL DEFAULT '',
            `payment_postcode` VARCHAR(32) NOT NULL DEFAULT '',
            `payment_country` VARCHAR(128) NOT NULL DEFAULT '',
            `payment_country_id` INT NOT NULL DEFAULT 0,
            `payment_zone` VARCHAR(128) NOT NULL DEFAULT '',
            `payment_zone_id` INT NOT NULL DEFAULT 0,
            `shipping_firstname` VARCHAR(64) NOT NULL DEFAULT '',
            `shipping_lastname` VARCHAR(64) NOT NULL DEFAULT '',
            `shipping_company` VARCHAR(128) NOT NULL DEFAULT '',
            `shipping_address_1` VARCHAR(128) NOT NULL DEFAULT '',
            `shipping_address_2` VARCHAR(128) NOT NULL DEFAULT '',
            `shipping_city` VARCHAR(128) NOT NULL DEFAULT '',
            `shipping_postcode` VARCHAR(32) NOT NULL DEFAULT '',
            `shipping_country` VARCHAR(128) NOT NULL DEFAULT '',
            `shipping_country_id` INT NOT NULL DEFAULT 0,
            `shipping_zone` VARCHAR(128) NOT NULL DEFAULT '',
            `shipping_zone_id` INT NOT NULL DEFAULT 0
        )");
        $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}order_product` (
            `order_product_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `order_id` INT NOT NULL,
            `product_id` INT NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `model` VARCHAR(64) NOT NULL DEFAULT '',
            `quantity` INT NOT NULL,
            `price` DECIMAL(15,4) NOT NULL,
            `total` DECIMAL(15,4) NOT NULL,
            `tax` DECIMAL(15,4) NOT NULL DEFAULT 0
        )");
        $db->query("CREATE TABLE IF NOT EXISTS `{$prefix}currency` (
            `currency_id` INT NOT NULL PRIMARY KEY,
            `code` VARCHAR(3) NOT NULL
        )");
        $db->query("REPLACE INTO `{$prefix}currency` (`currency_id`, `code`) VALUES (1, 'EUR'), (2, 'BGN')");
    }

    public static function seedNativeOrder(int $orderId, int $storeId, float $baseTotal, string $code = 'EUR', int $currencyId = 1, float $value = 1.0, array $nativeData = []): void
    {
        $db = self::connection();
        self::ensureNativeProofTables($db);
        $db->query("REPLACE INTO `" . $db->getPrefix() . "order`
            (`order_id`, `store_id`, `total`, `currency_code`, `currency_id`, `currency_value`)
            VALUES (" . $orderId . ", " . $storeId . ", " . $baseTotal . ", '" . $db->escape($code) . "', " . $currencyId . ", " . $value . ")");
        $fields = ['customer_id', 'customer_group_id', 'firstname', 'lastname', 'email', 'telephone'];
        foreach (['payment', 'shipping'] as $kind) {
            foreach (['company', 'address_1', 'address_2', 'city', 'postcode', 'country', 'country_id', 'zone', 'zone_id'] as $field) {
                $fields[] = $kind . '_' . $field;
            }
        }
        $fields = array_merge($fields, ['shipping_firstname', 'shipping_lastname']);
        $values = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $nativeData)) {
                $values[] = "`{$field}` = '" . $db->escape((string) $nativeData[$field]) . "'";
            }
        }
        if ($values !== []) {
            $db->query("UPDATE `" . $db->getPrefix() . "order` SET " . implode(', ', $values) . " WHERE `order_id` = {$orderId}");
        }
        if (isset($nativeData['products'])) {
            $db->query("DELETE FROM `" . $db->getPrefix() . "order_product` WHERE `order_id` = {$orderId}");
            foreach ($nativeData['products'] as $line) {
                $values = ["`order_id` = {$orderId}"];
                foreach (['product_id', 'name', 'model', 'quantity', 'price', 'total', 'tax'] as $field) {
                    $value = $line[$field] ?? (in_array($field, ['name', 'model'], true) ? '' : 0);
                    $values[] = "`{$field}` = '" . $db->escape((string) $value) . "'";
                }
                $db->query("INSERT INTO `" . $db->getPrefix() . "order_product` SET " . implode(', ', $values));
            }
        }
    }

    public static function seedSuccessfulEurAttempt(int $attemptId, int $orderId, \Opencart\System\Library\Extension\MtUniCredit\ValidatedFinancingSubmission $submission, int $cpId = 901, bool $process2 = false): void
    {
        $db = self::connection();
        self::seedNativeOrder(
            $orderId,
            $submission->storeId,
            $submission->orderDraft->orderTotal,
            'EUR',
            $submission->orderDraft->currencyId,
            $submission->orderDraft->currencyValue
        );
        $calc = $submission->financingCalculation;
        $payload = [
            'order_id' => substr((string) $orderId, 0, 13),
            'currency' => 'EUR',
            'price' => $calc->financedAmount,
            'parva' => $calc->firstInstallment->amount,
            'vnoska' => $calc->monthlyInstallment,
        ];
        $snapshot = \Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationSnapshot::fromSubmission(
            $submission,
            $orderId,
            $process2,
            $cpId
        )->toArray();
        $table = $db->getPrefix() . \Opencart\System\Library\Extension\MtUniCredit\PersistenceTableNames::FINANCING_ATTEMPT;
        $db->query("UPDATE `{$table}` SET `state` = 'cp_created',
            `control_panel_order_id` = " . $cpId . ",
            `cp_payload` = '" . $db->escape(json_encode($payload, JSON_THROW_ON_ERROR)) . "',
            `leasing_presentation_json` = '" . $db->escape(json_encode($snapshot, JSON_THROW_ON_ERROR)) . "'
            WHERE `attempt_id` = " . $attemptId);
    }

    /**
     * Drop only this harness's isolated tables. Never touches production `*_mt_uni_credit_*`
     * when the active prefix lacks {@see INTEGRATION_PREFIX_MARKER}.
     */
    public static function dropOwnTables(): void
    {
        if (self::$connection === null) {
            return;
        }

        $prefix = self::$activePrefix !== '' ? self::$activePrefix : self::$connection->getPrefix();
        self::assertSafeIntegrationPrefix($prefix);

        foreach (PersistenceTableNames::allPersistenceTables() as $table) {
            $full = $prefix . $table;
            self::$connection->query('DROP TABLE IF EXISTS `' . $full . '`');
        }
        self::$connection->query('DROP TABLE IF EXISTS `' . $prefix . 'order_product`');
        self::$connection->query('DROP TABLE IF EXISTS `' . $prefix . 'order`');
        self::$connection->query('DROP TABLE IF EXISTS `' . $prefix . 'currency`');
    }

    /**
     * List full table names this harness would create for the configured prefix.
     *
     * @return list<string>
     */
    public static function expectedIntegrationTableNames(): array
    {
        $config = self::loadDatabaseConfig();
        self::assertSafeIntegrationPrefix($config['prefix']);
        $names = [];
        foreach (PersistenceTableNames::allPersistenceTables() as $table) {
            $names[] = $config['prefix'] . $table;
        }

        return $names;
    }

    public static function assertSafeIntegrationPrefix(string $prefix): void
    {
        if ($prefix === '' || !str_contains($prefix, self::INTEGRATION_PREFIX_MARKER)) {
            throw new \RuntimeException(
                'Refusing integration DB operations: prefix must contain "'
                . self::INTEGRATION_PREFIX_MARKER
                . '" (got "' . $prefix . '").'
            );
        }
        // Production OpenCart tables use e.g. oc_mt_uni_credit_* — never allow that bare layout.
        if (preg_match('/(^|_)mt_uni_credit_?$/', rtrim($prefix, '_')) === 1
            && !str_contains($prefix, self::INTEGRATION_PREFIX_MARKER)
        ) {
            throw new \RuntimeException('Refusing operations against a production-looking prefix.');
        }
    }

    private static function registerShutdownCleanup(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            try {
                self::dropOwnTables();
            } catch (\Throwable) {
                // Teardown must not mask the original test failure.
            }
        });
    }

    /** @return array{hostname:string,username:string,password:string,database:string,port:int,prefix:string} */
    private static function loadDatabaseConfig(): array
    {
        $prefix = getenv('MT_UNI_CREDIT_DB_PREFIX') ?: 'oc_mtuni_it_';
        $hostname = getenv('MT_UNI_CREDIT_DB_HOST') ?: 'localhost';
        $username = getenv('MT_UNI_CREDIT_DB_USER') ?: '';
        $password = getenv('MT_UNI_CREDIT_DB_PASS') ?: '';
        $database = getenv('MT_UNI_CREDIT_DB_NAME') ?: '';
        $port = (int) (getenv('MT_UNI_CREDIT_DB_PORT') ?: '3306');

        if ($username === '' || $database === '') {
            $root = getenv('OPENCART_ROOT') ?: '/var/www/open40.avalonbg.com';
            $configFile = rtrim($root, '/') . '/config.php';
            if (is_file($configFile)) {
                if (!defined('DB_HOSTNAME')) {
                    require $configFile;
                }
                $hostname = defined('DB_HOSTNAME') ? \DB_HOSTNAME : $hostname;
                $username = defined('DB_USERNAME') ? \DB_USERNAME : $username;
                $password = defined('DB_PASSWORD') ? \DB_PASSWORD : $password;
                $database = defined('DB_DATABASE') ? \DB_DATABASE : $database;
                $port = defined('DB_PORT') ? (int) \DB_PORT : $port;
            }
        }

        if ($username === '' || $database === '') {
            throw new \RuntimeException('Integration DB credentials are not configured.');
        }

        if ($hostname === 'localhost') {
            $hostname = '127.0.0.1';
        }

        return [
            'hostname' => $hostname,
            'username' => $username,
            'password' => $password,
            'database' => $database,
            'port'     => $port,
            'prefix'   => $prefix,
        ];
    }
}
