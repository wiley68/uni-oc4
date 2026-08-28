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
    public const TEST_STORE_ID = 900001;
    public const TEST_STORE_ID_B = 900002;
    public const TEST_UNICID = 'test-unicid-phase3';
    public const TEST_UNICID_B = 'test-unicid-phase3-b';

    private static ?DbConnection $connection = null;

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
        (new PersistenceSchemaInstaller(self::$connection))->installAll();

        return self::$connection;
    }

    public static function resetTables(): void
    {
        $db = self::connection();
        foreach (PersistenceTableNames::allPersistenceTables() as $table) {
            $db->query('TRUNCATE TABLE `' . $db->getPrefix() . $table . '`');
        }
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
                require $configFile;
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
