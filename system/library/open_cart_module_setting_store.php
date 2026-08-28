<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Store-scoped oc_setting access for module CP state. */
final class OpenCartModuleSettingStore implements ModuleSettingStore
{
    private DbConnection $db;

    private string $settingCode;

    public function __construct(DbConnection $db, string $settingCode = ModuleConstants::MODULE_SETTING_CODE)
    {
        $this->db = $db;
        $this->settingCode = $settingCode;
    }

    public function get(int $storeId, string $key): ?string
    {
        if ($storeId < 0) {
            return null;
        }

        $table = $this->db->getPrefix() . 'setting';
        $result = $this->db->query(
            "SELECT `value` FROM `{$table}`
             WHERE `store_id` = " . (int) $storeId . "
               AND `key` = '" . $this->db->escape($key) . "'
             LIMIT 1"
        );

        if (!is_object($result) || $result->num_rows !== 1) {
            return null;
        }

        $value = $result->row['value'] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    public function set(int $storeId, string $key, string $value): void
    {
        if ($storeId < 0) {
            throw new PersistenceValidationException('Store scope is required.');
        }

        $table = $this->db->getPrefix() . 'setting';
        $existing = $this->get($storeId, $key);
        if ($existing === null) {
            $this->db->query(
                "INSERT INTO `{$table}` (`store_id`, `code`, `key`, `value`, `serialized`)
                 VALUES (
                    " . (int) $storeId . ",
                    '" . $this->db->escape($this->settingCode) . "',
                    '" . $this->db->escape($key) . "',
                    '" . $this->db->escape($value) . "',
                    0
                 )"
            );

            return;
        }

        $this->db->query(
            "UPDATE `{$table}`
             SET `value` = '" . $this->db->escape($value) . "'
             WHERE `store_id` = " . (int) $storeId . "
               AND `key` = '" . $this->db->escape($key) . "'"
        );
    }

    public function delete(int $storeId, string $key): void
    {
        if ($storeId < 0) {
            return;
        }

        $table = $this->db->getPrefix() . 'setting';
        $this->db->query(
            "DELETE FROM `{$table}`
             WHERE `store_id` = " . (int) $storeId . "
               AND `key` = '" . $this->db->escape($key) . "'"
        );
    }
}
