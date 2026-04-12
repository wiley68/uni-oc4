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
     * Главни категории (parent_id = 0). Админският и каталожният Category модел в OC4 имат различни сигнатури на getCategories().
     *
     * @return array<int, array<string, mixed>>
     */
    private function getTopLevelCategoryRows(): array
    {
        $this->load->model('catalog/category');
        // OC задава APPLICATION като „Catalog“ / „Admin“ (виж system/framework.php).
        $application = strtolower((string) ($this->config->get('application') ?? ''));

        if ($application === 'catalog') {
            return $this->model_catalog_category->getCategories(0);
        }

        return $this->model_catalog_category->getCategories([
            'filter_parent_id' => 0,
            'sort'             => 'sort_order',
            'order'            => 'ASC',
        ]);
    }

    /**
     * @return list<int>
     */
    public function getTopLevelCategoryIds(): array
    {
        $categories = $this->getTopLevelCategoryRows();
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
        $categories = $this->getTopLevelCategoryRows();
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

    /**
     * Изтрива редове от API кеша с group=coeff с date_upd преди началото на днешния ден (като PS UniCreditGetCoeffService).
     */
    public function purgeCoeffCacheOlderThanToday(): int
    {
        $table = $this->table(UnicreditConfig::TABLE_API_CACHE);
        $threshold = $this->getSqlDatetimeStartOfToday();
        $q = $this->db->query(
            "SELECT COUNT(*) AS `c` FROM `{$table}` WHERE `cache_group` = 'coeff' AND `date_upd` < '" . $this->db->escape($threshold) . "'"
        );
        $cnt = (int) ($q->row['c'] ?? 0);
        if ($cnt > 0) {
            $this->db->query(
                "DELETE FROM `{$table}` WHERE `cache_group` = 'coeff' AND `date_upd` < '" . $this->db->escape($threshold) . "'"
            );
        }

        return $cnt;
    }

    /**
     * Нулира kimb / kimb_time / stats за всички главни категории (запазва kop/promo); създава липсващи редове.
     * Аналог на {@see KopMappingService::refreshMappings} в PS модула.
     */
    public function refreshKopMappingsResetStats(): bool
    {
        foreach ($this->getTopLevelCategoryMappings() as $row) {
            $this->resetBankFieldsForCategory((int) $row['category_id'], (string) $row['kop'], (string) $row['promo']);
        }

        return true;
    }

    /**
     * Същият pipeline като админ „Ръчно обновяване на кеша“: coeff purge, нулиране на KOP stats, опресняване на getparameters.
     *
     * @return array{result: string, kop_refreshed: bool, params_refreshed: bool, coeff_purged: int}
     */
    public function runBankPanelRefreshPipeline(string $unicid): array
    {
        $out = [
            'result' => 'error',
            'kop_refreshed' => false,
            'params_refreshed' => false,
            'coeff_purged' => 0,
        ];

        $unicid = trim($unicid);
        if ($unicid === '') {
            return $out;
        }

        $out['coeff_purged'] = $this->purgeCoeffCacheOlderThanToday();
        $this->refreshKopMappingsResetStats();
        $out['kop_refreshed'] = true;

        $params = $this->fetchUniParamsFromBankAndCache($unicid, true);
        $out['params_refreshed'] = is_array($params);

        $out['result'] = $out['params_refreshed'] ? 'success' : 'partial';

        return $out;
    }

    /**
     * GET getparameters.php?cid=… и запис в api_cache (params:md5(cid)); при $forceReload пропуска TTL.
     *
     * @return array<string, mixed>|null
     */
    public function fetchUniParamsFromBankAndCache(string $unicid, bool $forceReload): ?array
    {
        $unicid = trim($unicid);
        if ($unicid === '') {
            return null;
        }

        $cacheKey = 'params:' . md5($unicid);

        if (!$forceReload) {
            $cached = $this->readApiCachePayload($cacheKey, UnicreditConfig::API_CACHE_TTL_PARAMS);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $url = rtrim(UnicreditConfig::LIVE_URL, '/') . UnicreditConfig::BANK_GETPARAMETERS_PATH . '?cid=' . rawurlencode($unicid);

        if (!\function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init();
        curl_setopt($ch, \CURLOPT_URL, $url);
        curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, \CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, \CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, \CURLOPT_TIMEOUT, 6);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);

        if ($response === false || $httpCode !== 200) {
            return null;
        }

        $params = json_decode((string) $response, true);
        if (!is_array($params)) {
            return null;
        }

        $this->writeApiCachePayload($cacheKey, 'params', $params);

        return $params;
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

    private function resetBankFieldsForCategory(int $categoryId, string $kop, string $promo): void
    {
        $table = $this->table(UnicreditConfig::TABLE_KOP_MAPPING);
        $now = date('Y-m-d H:i:s');
        $statsJson = $this->getDefaultStatsJson();
        $q = $this->db->query("SELECT `category_id` FROM `{$table}` WHERE `category_id` = '" . (int) $categoryId . "' LIMIT 1");

        if ($q->num_rows) {
            $this->db->query(
                "UPDATE `{$table}` SET `kimb` = '', `kimb_time` = '0', `stats` = '" . $this->db->escape($statsJson) . "', `date_upd` = '" . $this->db->escape($now) . "' WHERE `category_id` = '" . (int) $categoryId . "'"
            );
        } else {
            $this->db->query(
                "INSERT INTO `{$table}` SET `category_id` = '" . (int) $categoryId . "', `kop` = '" . $this->db->escape($kop) . "', `promo` = '" . $this->db->escape($promo) . "', `kimb` = '', `kimb_time` = '0', `stats` = '" . $this->db->escape($statsJson) . "', `date_add` = '" . $this->db->escape($now) . "', `date_upd` = '" . $this->db->escape($now) . "'"
            );
        }
    }

    private function getDefaultStatsJson(): string
    {
        $stats = [
            'kimb_3' => '',
            'glp_3' => '',
            'kimb_4' => '',
            'glp_4' => '',
            'kimb_5' => '',
            'glp_5' => '',
            'kimb_6' => '',
            'glp_6' => '',
            'kimb_9' => '',
            'glp_9' => '',
            'kimb_10' => '',
            'glp_10' => '',
            'kimb_12' => '',
            'glp_12' => '',
            'kimb_15' => '',
            'glp_15' => '',
            'kimb_18' => '',
            'glp_18' => '',
            'kimb_24' => '',
            'glp_24' => '',
            'kimb_30' => '',
            'glp_30' => '',
            'kimb_36' => '',
            'glp_36' => '',
        ];
        $json = json_encode($stats, \JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '{}';
    }

    private function getSqlDatetimeStartOfToday(): string
    {
        $tzName = (string) ($this->config->get('config_timezone') ?? '');
        if ($tzName !== '') {
            try {
                return (new \DateTimeImmutable('today', new \DateTimeZone($tzName)))->format('Y-m-d H:i:s');
            } catch (\Exception) {
            }
        }

        return date('Y-m-d 00:00:00');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readApiCachePayload(string $cacheKey, int $ttlSeconds): ?array
    {
        $table = $this->table(UnicreditConfig::TABLE_API_CACHE);
        $q = $this->db->query(
            "SELECT `payload`, `date_upd` FROM `{$table}` WHERE `cache_key` = '" . $this->db->escape($cacheKey) . "' LIMIT 1"
        );
        if (!$q->num_rows) {
            return null;
        }
        $updatedTs = strtotime((string) ($q->row['date_upd'] ?? ''));
        if ($updatedTs === false || (time() - $updatedTs) >= $ttlSeconds) {
            return null;
        }
        $payload = json_decode((string) ($q->row['payload'] ?? ''), true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeApiCachePayload(string $cacheKey, string $group, array $payload): void
    {
        $json = json_encode($payload, \JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }
        $table = $this->table(UnicreditConfig::TABLE_API_CACHE);
        $now = date('Y-m-d H:i:s');
        $q = $this->db->query(
            "SELECT `id_mt_uni_credit_api_cache` FROM `{$table}` WHERE `cache_key` = '" . $this->db->escape($cacheKey) . "' LIMIT 1"
        );
        if ($q->num_rows) {
            $this->db->query(
                "UPDATE `{$table}` SET `cache_group` = '" . $this->db->escape($group) . "', `payload` = '" . $this->db->escape($json) . "', `date_upd` = '" . $this->db->escape($now) . "' WHERE `cache_key` = '" . $this->db->escape($cacheKey) . "'"
            );
        } else {
            $this->db->query(
                "INSERT INTO `{$table}` SET `cache_group` = '" . $this->db->escape($group) . "', `cache_key` = '" . $this->db->escape($cacheKey) . "', `payload` = '" . $this->db->escape($json) . "', `date_add` = '" . $this->db->escape($now) . "', `date_upd` = '" . $this->db->escape($now) . "'"
            );
        }
    }

    private function table(string $nameWithoutPrefix): string
    {
        return \DB_PREFIX . $nameWithoutPrefix;
    }
}
