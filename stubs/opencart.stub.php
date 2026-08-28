<?php

/**
 * OpenCart 4 — IDE stubs за Intelephense.
 *
 * Работното пространство е само разширението (без core на OpenCart), затова тук
 * се описват минималните класове/константи, които редакторът не вижда иначе.
 *
 * Не се зареждат от OpenCart при runtime.
 */

namespace {
    if (!defined('DB_PREFIX')) {
        define('DB_PREFIX', 'oc_');
    }
    if (!defined('DB_HOSTNAME')) {
        define('DB_HOSTNAME', 'localhost');
    }
    if (!defined('DB_USERNAME')) {
        define('DB_USERNAME', '');
    }
    if (!defined('DB_PASSWORD')) {
        define('DB_PASSWORD', '');
    }
    if (!defined('DB_DATABASE')) {
        define('DB_DATABASE', '');
    }
    if (!defined('DIR_STORAGE')) {
        define('DIR_STORAGE', '');
    }
    if (!defined('DB_PORT')) {
        define('DB_PORT', '3306');
    }
    if (!defined('DIR_EXTENSION')) {
        /** Абсолютен път към папката extension/ на OpenCart (като в config.php). */
        define('DIR_EXTENSION', '');
    }
    if (!defined('DIR_APPLICATION')) {
        /** В админ: …/admin/ ; в каталог: …/catalog/ */
        define('DIR_APPLICATION', '');
    }
    if (!defined('DIR_CATALOG')) {
        /** Публичен каталог на магазина (само в admin config.php). */
        define('DIR_CATALOG', '');
    }
    if (!defined('VERSION')) {
        define('VERSION', '4.1.0.3');
    }

    /**
     * @see extension/mt_uni_credit/system/library/uni_financial_rate.php
     */
    final class MtUniCreditFinancialRate
    {
        public static function periodicRate(
            float $nper,
            float $pmt,
            float $pv,
            float $fv = 0.0,
            int $type = 0,
            float $guess = 0.1
        ): float {
            return 0.0;
        }

        public static function formatGprPercentForDisplay(float $percentPoints): string
        {
            return '0.00';
        }
    }
}

namespace Opencart\System\Engine {

    class Registry
    {
        public function get(string $key): ?object
        {
            return null;
        }

        public function set(string $key, object $value): void {}

        public function has(string $key): bool
        {
            return false;
        }
    }

    class Config
    {
        public function get(string $key): mixed
        {
            return null;
        }

        public function set(string $key, mixed $value): void {}
    }

    /**
     * Прокси към методите на заредения модел (install, uninstall, …).
     *
     * @method void install()
     * @method void uninstall()
     */
    class Proxy
    {
        /** @param array<int, mixed> $args */
        public function __call(string $name, array $args): mixed
        {
            return null;
        }
    }

    class Loader
    {
        public function model(string $route): void {}

        public function language(string $route): void {}

        /** @param mixed $args */
        public function controller(string $route, ...$args): mixed
        {
            return null;
        }

        /** @param array<string, mixed> $data */
        public function view(string $route, array $data = [], string $code = ''): string
        {
            return '';
        }
    }

    class Document
    {
        public function setTitle(string $title): void {}

        public function addStyle(string $href, string $rel = 'stylesheet', string $media = 'screen'): void {}

        public function addScript(string $href, string $position = 'header'): void {}
    }

    /**
     * Базов контролер (admin/catalog). Чрез __get се достъпват ключове от registry.
     *
     * @property Loader $load
     * @property \Opencart\System\Library\Language $language
     * @property Config $config
     * @property \Opencart\System\Library\Request $request
     * @property \Opencart\System\Library\Response $response
     * @property \Opencart\System\Library\Session $session
     * @property \Opencart\System\Library\Url $url
     * @property Document $document
     * @property \Opencart\System\Library\Cart\User $user
     * @property \Opencart\System\Library\Cart\Customer $customer
     * @property \Opencart\System\Library\Cart\Currency $currency
     * @property \Opencart\System\Library\Log $log
     * @property Proxy $model_setting_setting
     * @property Proxy $model_extension_mt_uni_credit_module_unicredit
     * @property \Opencart\Catalog\Model\Extension\MtUniCredit\Module\MtUniCreditProduct $model_extension_mt_uni_credit_module_mt_uni_credit_product
     */
    class Controller
    {
        protected Registry $registry;

        public function __construct(Registry $registry) {}

        public function __get(string $key): object
        {
            return new \stdClass();
        }

        public function __set(string $key, object $value): void {}
    }

    /**
     * @property \Opencart\System\Library\DB $db
     * @property Config $config
     * @property \Opencart\System\Library\Request $request
     * @property \Opencart\System\Library\Session $session
     * @property \Opencart\System\Library\Cart\Customer $customer
     * @property Loader $load
     * @property Proxy $model_catalog_category
     */
    class Model
    {
        protected Registry $registry;

        public function __construct(Registry $registry) {}

        public function __get(string $key): object
        {
            return new \stdClass();
        }

        public function __set(string $key, object $value): void {}
    }
}

namespace Opencart\System\Library {

    /**
     * Резултат от $this->db->query() в типичния OC адаптор.
     */
    class DBResult
    {
        /** @var list<array<string, mixed>> */
        public array $rows = [];

        public int $num_rows = 0;

        /** @var mixed */
        public $row;
    }

    class DB
    {
        public function query(string $sql): \Opencart\System\Library\DBResult
        {
            return new DBResult();
        }

        public function escape(string $value): string
        {
            return '';
        }

        public function getLastId(): int
        {
            return 0;
        }
    }

    class Language
    {
        public function get(string $key): string
        {
            return '';
        }
    }

    class Request
    {
        /** @var array<string, mixed> */
        public array $get = [];

        /** @var array<string, mixed> */
        public array $post = [];

        /** @var array<string, mixed> */
        public array $cookie = [];

        /** @var array<string, mixed> */
        public array $server = [];
    }

    class Response
    {
        public function addHeader(string $header): void {}

        public function setOutput(string $output): void {}

        public function redirect(string $url): void {}
    }

    class Session
    {
        /** @var array<string, mixed> */
        public array $data = [];

        public function getId(): string
        {
            return '';
        }
    }

    class Url
    {
        public function link(string $route, string $args = '', bool $js = false): string
        {
            return '';
        }
    }

    class Log
    {
        public function write(string $message): void {}
    }
}

namespace Opencart\System\Library\Extension\MtUniCredit {

    class PersistenceException extends \RuntimeException {}

    final class PersistenceValidationException extends PersistenceException {}

    final class PersistenceConflictException extends PersistenceException {}

    final class PersistenceNotFoundException extends PersistenceException {}

    final class OrderMaterializationException extends \RuntimeException {}
}

namespace Opencart\System\Library\Cart {

    class User
    {
        public function hasPermission(string $type, string $route): bool
        {
            return false;
        }

        public function getId(): int
        {
            return 0;
        }
    }

    class Customer
    {
        public function isLogged(): bool
        {
            return false;
        }

        public function getId(): int
        {
            return 0;
        }

        public function getEmail(): string
        {
            return '';
        }
    }

    class Currency
    {
        public function getId(string $currency): int
        {
            return 0;
        }

        public function getValue(string $currency): float
        {
            return 1.0;
        }

        public function convert(float $value, string $from, string $to): float
        {
            return $value;
        }
    }
}
