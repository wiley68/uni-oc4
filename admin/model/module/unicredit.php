<?php

namespace Opencart\Admin\Model\Extension\MtUniCredit\Module;

require_once __DIR__ . '/unicredit_config.php';

class Unicredit extends \Opencart\System\Engine\Model
{
    public const KOP_MAX_LENGTH = 64;

    public function install(): void
    {
        $this->createKopMappingTable();
        $this->createApiCacheTable();
    }

    public function uninstall(): void
    {
        $this->dropTable(UnicreditConfig::TABLE_KOP_MAPPING);
        $this->dropTable(UnicreditConfig::TABLE_API_CACHE);
    }

    /**
     * @return list<int>
     */
    public function getTopLevelCategoryIds(): array
    {
        $this->load->model('catalog/category');
        $categories = $this->model_catalog_category->getCategories([
            'filter_parent_id' => 0,
            'sort'             => 'sort_order',
            'order'            => 'ASC'
        ]);
        $ids = [];
        foreach ($categories as $cat) {
            $id = (int) ($cat['category_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Редове за таблица в админ: главни категории (parent_id = 0) + kop/promo от DB.
     *
     * @return list<array{category_id: int, name: string, kop: string, promo: string}>
     */
    public function getTopLevelCategoryMappings(): array
    {
        $this->load->model('catalog/category');
        $categories = $this->model_catalog_category->getCategories([
            'filter_parent_id' => 0,
            'sort'             => 'sort_order',
            'order'            => 'ASC'
        ]);
        $indexed = $this->getMappingsIndexedByCategoryId();
        $rows = [];
        foreach ($categories as $cat) {
            $id = (int) ($cat['category_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $row = [
                'category_id' => $id,
                'name'        => (string) ($cat['name'] ?? ''),
                'kop'         => '',
                'promo'       => '',
            ];
            if (isset($indexed[$id])) {
                $row['kop'] = $indexed[$id]['kop'];
                $row['promo'] = $indexed[$id]['promo'];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<int|string, mixed> $posted от request: kop_map[category_id][kop|promo]
     */
    public function saveKopMappingsFromPost(array $posted): void
    {
        $allowed = array_flip($this->getTopLevelCategoryIds());
        foreach ($posted as $cidKey => $fields) {
            $cid = (int) $cidKey;
            if ($cid <= 0 || !isset($allowed[$cid]) || !is_array($fields)) {
                continue;
            }
            $kop = isset($fields['kop']) ? trim((string) $fields['kop']) : '';
            $promo = isset($fields['promo']) ? trim((string) $fields['promo']) : '';
            $this->upsertMapping($cid, $kop, $promo);
        }
    }

    private function createKopMappingTable(): void
    {
        $table = $this->table(UnicreditConfig::TABLE_KOP_MAPPING);

        $this->db->query("CREATE TABLE IF NOT EXISTS `{$table}` (
            `id_mt_uni_credit_kop` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `category_id` int(11) UNSIGNED NOT NULL,
            `kop` varchar(64) NOT NULL DEFAULT '',
            `promo` varchar(64) NOT NULL DEFAULT '',
            `kimb` varchar(32) NOT NULL DEFAULT '',
            `kimb_time` int(11) UNSIGNED NOT NULL DEFAULT '0',
            `stats` longtext DEFAULT NULL,
            `date_add` datetime NOT NULL,
            `date_upd` datetime NOT NULL,
            PRIMARY KEY (`id_mt_uni_credit_kop`),
            UNIQUE KEY `uniq_mt_uni_credit_kop_category` (`category_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    private function createApiCacheTable(): void
    {
        $table = $this->table(UnicreditConfig::TABLE_API_CACHE);

        $this->db->query("CREATE TABLE IF NOT EXISTS `{$table}` (
            `id_mt_uni_credit_api_cache` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `cache_group` varchar(32) NOT NULL,
            `cache_key` varchar(191) NOT NULL,
            `payload` longtext DEFAULT NULL,
            `date_add` datetime NOT NULL,
            `date_upd` datetime NOT NULL,
            PRIMARY KEY (`id_mt_uni_credit_api_cache`),
            UNIQUE KEY `uniq_mt_uni_credit_api_cache_key` (`cache_key`),
            KEY `idx_mt_uni_credit_api_cache_group_upd` (`cache_group`, `date_upd`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    private function dropTable(string $nameWithoutPrefix): void
    {
        $table = $this->table($nameWithoutPrefix);
        $this->db->query("DROP TABLE IF EXISTS `{$table}`");
    }

    /**
     * @return array<int, array{kop: string, promo: string, kimb: string, kimb_time: int, stats: string}>
     */
    private function getMappingsIndexedByCategoryId(): array
    {
        $table = $this->table(UnicreditConfig::TABLE_KOP_MAPPING);
        $q = $this->db->query("SELECT `category_id`, `kop`, `promo`, `kimb`, `kimb_time`, `stats` FROM `{$table}`");
        $indexed = [];
        foreach ($q->rows as $dbRow) {
            $cid = (int) ($dbRow['category_id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $indexed[$cid] = [
                'kop'       => (string) ($dbRow['kop'] ?? ''),
                'promo'     => (string) ($dbRow['promo'] ?? ''),
                'kimb'      => (string) ($dbRow['kimb'] ?? ''),
                'kimb_time' => (int) ($dbRow['kimb_time'] ?? 0),
                'stats'     => (string) ($dbRow['stats'] ?? ''),
            ];
        }

        return $indexed;
    }

    private function upsertMapping(int $categoryId, string $kop, string $promo): void
    {
        $table = $this->table(UnicreditConfig::TABLE_KOP_MAPPING);
        $now = date('Y-m-d H:i:s');
        $q = $this->db->query("SELECT `kimb`, `kimb_time`, `stats` FROM `{$table}` WHERE `category_id` = '" . (int) $categoryId . "' LIMIT 1");

        if ($q->num_rows) {
            $this->db->query("UPDATE `{$table}` SET `kop` = '" . $this->db->escape($kop) . "', `promo` = '" . $this->db->escape($promo) . "', `date_upd` = '" . $this->db->escape($now) . "' WHERE `category_id` = '" . (int) $categoryId . "'");
        } else {
            $stats = '{}';
            $this->db->query("INSERT INTO `{$table}` SET `category_id` = '" . (int) $categoryId . "', `kop` = '" . $this->db->escape($kop) . "', `promo` = '" . $this->db->escape($promo) . "', `kimb` = '', `kimb_time` = '0', `stats` = '" . $this->db->escape($stats) . "', `date_add` = '" . $this->db->escape($now) . "', `date_upd` = '" . $this->db->escape($now) . "'");
        }
    }

    private function table(string $nameWithoutPrefix): string
    {
        return \DB_PREFIX . $nameWithoutPrefix;
    }
}
