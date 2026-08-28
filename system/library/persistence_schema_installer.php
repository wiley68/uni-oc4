<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Idempotent Phase 3 schema installer — no DROP on uninstall.
 */
final class PersistenceSchemaInstaller
{
    private DbConnection $db;

    public function __construct(DbConnection $db)
    {
        $this->db = $db;
    }

    public function installAll(): void
    {
        foreach (self::createTableStatements($this->db->getPrefix()) as $sql) {
            $this->db->query($sql);
        }
    }

    /**
     * @return list<string>
     */
    public static function createTableStatements(string $prefix): array
    {
        $shopCache = $prefix . PersistenceTableNames::SHOP_CACHE;
        $apiNonce = $prefix . PersistenceTableNames::API_NONCE;
        $operationLock = $prefix . PersistenceTableNames::OPERATION_LOCK;
        $financingAttempt = $prefix . PersistenceTableNames::FINANCING_ATTEMPT;

        return [
            "CREATE TABLE IF NOT EXISTS `{$shopCache}` (
                `shop_cache_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `unicid` VARCHAR(64) NOT NULL,
                `shop_data` LONGTEXT NOT NULL,
                `fetched_at` DATETIME NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`shop_cache_id`),
                UNIQUE KEY `uniq_mt_uni_credit_shop_cache_store_unicid` (`store_id`, `unicid`),
                KEY `idx_mt_uni_credit_shop_cache_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$apiNonce}` (
                `api_nonce_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `unicid` VARCHAR(64) NOT NULL,
                `nonce_hash` CHAR(64) NOT NULL,
                `used_at` DATETIME NOT NULL,
                `expires_at` DATETIME NOT NULL,
                PRIMARY KEY (`api_nonce_id`),
                UNIQUE KEY `uniq_mt_uni_credit_api_nonce` (`store_id`, `unicid`, `nonce_hash`),
                KEY `idx_mt_uni_credit_api_nonce_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$operationLock}` (
                `operation_lock_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `entry_point` VARCHAR(16) NOT NULL,
                `operation_key_hash` CHAR(64) NOT NULL,
                `owner_token` CHAR(32) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`operation_lock_id`),
                UNIQUE KEY `uniq_mt_uni_credit_operation_lock` (`store_id`, `entry_point`, `operation_key_hash`),
                KEY `idx_mt_uni_credit_operation_lock_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$financingAttempt}` (
                `attempt_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `entry_point` VARCHAR(16) NOT NULL,
                `submission_token` CHAR(64) NULL,
                `operation_key_hash` CHAR(64) NOT NULL,
                `actor_binding_hash` CHAR(64) NOT NULL,
                `selection_hash` CHAR(64) NOT NULL,
                `cart_id` INT UNSIGNED NULL,
                `cart_fingerprint` CHAR(64) NULL,
                `state` VARCHAR(32) NOT NULL,
                `order_id` INT UNSIGNED NULL,
                `control_panel_order_id` VARCHAR(13) NULL,
                `cp_payload` LONGTEXT NULL,
                `last_error_class` VARCHAR(64) NULL,
                `expires_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`attempt_id`),
                UNIQUE KEY `uniq_mt_uni_credit_submission_token` (`submission_token`),
                UNIQUE KEY `uniq_mt_uni_credit_store_order` (`store_id`, `order_id`),
                KEY `idx_mt_uni_credit_attempt_operation` (`store_id`, `entry_point`, `operation_key_hash`, `state`),
                KEY `idx_mt_uni_credit_attempt_cart` (`store_id`, `cart_id`, `state`),
                KEY `idx_mt_uni_credit_attempt_state_updated` (`state`, `updated_at`),
                KEY `idx_mt_uni_credit_attempt_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    /** @return list<string> */
    public function installedTableNames(): array
    {
        $names = [];
        foreach (PersistenceTableNames::phase3Tables() as $table) {
            $names[] = $this->db->getPrefix() . $table;
        }

        return $names;
    }
}
