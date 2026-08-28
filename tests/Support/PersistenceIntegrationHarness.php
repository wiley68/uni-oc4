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
            throw new \RuntimeException('Integration tests disabled (set MT_UNI_CREDIT_INTEGRATION=1).');
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
        self::registerShutdownCleanup();

        return self::$connection;
    }

    public static function resetTables(): void
    {
        $db = self::connection();
        // Idempotent: recreate if a prior teardown/drop removed tables mid-suite.
        (new PersistenceSchemaInstaller($db))->installAll();
        foreach (PersistenceTableNames::allPersistenceTables() as $table) {
            $db->query('TRUNCATE TABLE `' . $db->getPrefix() . $table . '`');
        }
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
