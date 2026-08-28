<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use Opencart\System\Library\Extension\MtUniCredit\MysqliDbConnection;

/**
 * Real OpenCart order table access for Phase 6 integration (oc_ prefix, synthetic markers only).
 */
final class OpenCartOrderIntegrationHarness
{
    private static ?MysqliDbConnection $connection = null;

    public static function enabled(): bool
    {
        return getenv('MT_UNI_CREDIT_INTEGRATION') === '1';
    }

    public static function connection(): MysqliDbConnection
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        if (!self::enabled()) {
            throw new \RuntimeException('Integration tests disabled.');
        }

        $config = self::loadOpenCartDatabaseConfig();
        $mysqli = new \mysqli(
            $config['hostname'],
            $config['username'],
            $config['password'],
            $config['database'],
            $config['port']
        );
        if ($mysqli->connect_errno) {
            throw new \RuntimeException('OpenCart DB connection failed.');
        }
        $mysqli->set_charset('utf8mb4');
        self::$connection = new MysqliDbConnection($mysqli, $config['prefix']);

        return self::$connection;
    }

    public static function orders(): SqlCheckoutOrderAdapter
    {
        return new SqlCheckoutOrderAdapter(self::connection());
    }

    public static function cleanupModuleTestOrders(): void
    {
        self::orders()->deleteTestOrdersByTrackingPrefix('mtuc:');
    }

    /** @return array{hostname:string,username:string,password:string,database:string,port:int,prefix:string} */
    private static function loadOpenCartDatabaseConfig(): array
    {
        if (defined('DB_HOSTNAME') && defined('DB_USERNAME') && defined('DB_DATABASE') && defined('DB_PREFIX')) {
            return [
                'hostname' => (string) \DB_HOSTNAME,
                'username' => (string) \DB_USERNAME,
                'password' => defined('DB_PASSWORD') ? (string) \DB_PASSWORD : '',
                'database' => (string) \DB_DATABASE,
                'port'     => defined('DB_PORT') ? (int) \DB_PORT : 3306,
                'prefix'   => (string) \DB_PREFIX,
            ];
        }

        $root = getenv('OPENCART_ROOT') ?: '/var/www/open40.avalonbg.com';
        $configFile = rtrim($root, '/') . '/config.php';
        if (!is_file($configFile)) {
            throw new \RuntimeException('OpenCart config.php not found.');
        }
        require $configFile;

        return [
            'hostname' => defined('DB_HOSTNAME') ? (string) \DB_HOSTNAME : '127.0.0.1',
            'username' => defined('DB_USERNAME') ? (string) \DB_USERNAME : '',
            'password' => defined('DB_PASSWORD') ? (string) \DB_PASSWORD : '',
            'database' => defined('DB_DATABASE') ? (string) \DB_DATABASE : '',
            'port'     => defined('DB_PORT') ? (int) \DB_PORT : 3306,
            'prefix'   => defined('DB_PREFIX') ? (string) \DB_PREFIX : 'oc_',
        ];
    }
}
