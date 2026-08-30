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
        $this->ensureUpgrades();
    }

    /**
     * Idempotent column upgrades for existing installs (CREATE IF NOT EXISTS does not alter).
     */
    private function ensureUpgrades(): void
    {
        $financingAttempt = $this->db->getPrefix() . PersistenceTableNames::FINANCING_ATTEMPT;
        try {
            // CP PK is BIGINT; legacy VARCHAR(13) was sized for shop order_id, not CP id.
            $this->db->query(
                "ALTER TABLE `{$financingAttempt}`
                 MODIFY COLUMN `control_panel_order_id` BIGINT UNSIGNED NULL"
            );
        } catch (\Throwable $exception) {
            // Table may be mid-install or already compatible; CREATE path uses BIGINT.
        }

        $columns = [
            'smartucf_state' => "VARCHAR(32) NOT NULL DEFAULT 'not_started'",
            'smartucf_session_id' => 'VARCHAR(128) NULL',
            'smartucf_redirect_url' => 'VARCHAR(768) NULL',
            'smartucf_http_code' => 'INT NULL',
            'smartucf_error_class' => 'VARCHAR(64) NULL',
            'smartucf_retryable' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'smartucf_claimed_at' => 'DATETIME NULL',
            'smartucf_completed_at' => 'DATETIME NULL',
        ];
        foreach ($columns as $column => $definition) {
            try {
                $result = $this->db->query(
                    "SHOW COLUMNS FROM `{$financingAttempt}` LIKE '" . $this->db->escape($column) . "'"
                );
                if (is_object($result) && (int) ($result->num_rows ?? 0) > 0) {
                    continue;
                }
                $this->db->query("ALTER TABLE `{$financingAttempt}` ADD COLUMN `{$column}` {$definition}");
            } catch (\Throwable $exception) {
                // Concurrent installer or restricted metadata access; retry remains idempotent.
            }
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
        $orderCorrelation = $prefix . PersistenceTableNames::ORDER_CORRELATION;
        $orderBankStatus = $prefix . PersistenceTableNames::ORDER_BANK_STATUS;
        $diagnosticDebugLog = $prefix . PersistenceTableNames::DIAGNOSTIC_DEBUG_LOG;

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
                `control_panel_order_id` BIGINT UNSIGNED NULL,
                `cp_payload` LONGTEXT NULL,
                `smartucf_state` VARCHAR(32) NOT NULL DEFAULT 'not_started',
                `smartucf_session_id` VARCHAR(128) NULL,
                `smartucf_redirect_url` VARCHAR(768) NULL,
                `smartucf_http_code` INT NULL,
                `smartucf_error_class` VARCHAR(64) NULL,
                `smartucf_retryable` TINYINT(1) NOT NULL DEFAULT 0,
                `smartucf_claimed_at` DATETIME NULL,
                `smartucf_completed_at` DATETIME NULL,
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

            "CREATE TABLE IF NOT EXISTS `{$orderCorrelation}` (
                `order_correlation_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `attempt_id` INT UNSIGNED NOT NULL,
                `order_id` INT UNSIGNED NOT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`order_correlation_id`),
                UNIQUE KEY `uniq_mt_uni_credit_correlation_attempt` (`attempt_id`),
                UNIQUE KEY `uniq_mt_uni_credit_correlation_store_order` (`store_id`, `order_id`),
                KEY `idx_mt_uni_credit_correlation_store_attempt` (`store_id`, `attempt_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$orderBankStatus}` (
                `order_bank_status_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `order_id` INT UNSIGNED NOT NULL,
                `order_reference` VARCHAR(64) NOT NULL,
                `status_id` VARCHAR(255) NOT NULL,
                `status_label` VARCHAR(255) NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`order_bank_status_id`),
                UNIQUE KEY `uniq_mt_uni_credit_order_bank_store_order` (`store_id`, `order_id`),
                KEY `idx_mt_uni_credit_order_bank_reference` (`order_reference`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `{$diagnosticDebugLog}` (
                `diagnostic_debug_log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_id` INT UNSIGNED NOT NULL,
                `order_id` INT UNSIGNED NOT NULL,
                `entry_point` VARCHAR(16) NOT NULL DEFAULT '',
                `event_code` VARCHAR(64) NOT NULL DEFAULT '',
                `http_status` INT NULL,
                `summary_json` LONGTEXT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`diagnostic_debug_log_id`),
                KEY `idx_mt_uni_credit_diag_store_order` (`store_id`, `order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    /** @return list<string> */
    public function installedTableNames(): array
    {
        $names = [];
        foreach (PersistenceTableNames::allPersistenceTables() as $table) {
            $names[] = $this->db->getPrefix() . $table;
        }

        return $names;
    }
}
