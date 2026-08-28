<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Validated Control Panel shop snapshot persistence (no live CP fetch in Phase 3).
 */
final class ShopCacheRepository
{
    private DbConnection $db;

    private PersistenceClock $clock;

    public function __construct(DbConnection $db, ?PersistenceClock $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?? new PersistenceClock();
    }

    /**
     * @return array{fetched_at: string, expires_at: string, is_fresh: bool}|null
     */
    public function findMetadata(int $storeId, string $unicid): ?array
    {
        $this->requireStoreId($storeId);
        $unicid = trim($unicid);
        if ($unicid === '') {
            return null;
        }

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT `fetched_at`, `expires_at`
             FROM `{$table}`
             WHERE `store_id` = " . (int) $storeId . "
               AND `unicid` = '" . $this->db->escape($unicid) . "'
             LIMIT 1"
        );

        if (!is_object($result) || $result->num_rows !== 1) {
            return null;
        }

        $row = $result->row;
        $now = $this->clock->formatUtc($this->clock->now());
        $expiresAt = (string) $row['expires_at'];

        return [
            'fetched_at' => (string) $row['fetched_at'],
            'expires_at' => $expiresAt,
            'is_fresh'   => $expiresAt > $now,
        ];
    }

    /**
     * @return array{shop_data: array<string, mixed>, fetched_at: string, expires_at: string}|null
     */
    public function findLatest(int $storeId, string $unicid): ?array
    {
        $this->requireStoreId($storeId);
        $unicid = trim($unicid);
        if ($unicid === '') {
            return null;
        }

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT `shop_data`, `fetched_at`, `expires_at`
             FROM `{$table}`
             WHERE `store_id` = " . (int) $storeId . "
               AND `unicid` = '" . $this->db->escape($unicid) . "'
             LIMIT 1"
        );

        if (!is_object($result) || $result->num_rows !== 1) {
            return null;
        }

        $row = $result->row;
        if (!isset($row['shop_data']) || !is_string($row['shop_data'])) {
            return null;
        }

        try {
            $decoded = json_decode($row['shop_data'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return null;
        }

        if (!is_array($decoded) || $decoded === []) {
            return null;
        }

        return [
            'shop_data'  => $decoded,
            'fetched_at' => (string) $row['fetched_at'],
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findFresh(int $storeId, string $unicid): ?array
    {
        $this->requireStoreId($storeId);
        $unicid = trim($unicid);
        if ($unicid === '') {
            return null;
        }

        $table = $this->tableName();
        $now = $this->clock->formatUtc($this->clock->now());
        $result = $this->db->query(
            "SELECT `shop_data`, `fetched_at`, `expires_at`
             FROM `{$table}`
             WHERE `store_id` = " . (int) $storeId . "
               AND `unicid` = '" . $this->db->escape($unicid) . "'
               AND `expires_at` > '" . $this->db->escape($now) . "'
             LIMIT 1"
        );

        if (!is_object($result) || $result->num_rows !== 1) {
            return null;
        }

        $row = $result->row;
        if (!isset($row['shop_data']) || !is_string($row['shop_data'])) {
            return null;
        }

        try {
            $decoded = json_decode($row['shop_data'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return null;
        }

        if (!is_array($decoded) || $decoded === []) {
            return null;
        }

        return [
            'shop_data'  => $decoded,
            'fetched_at' => (string) $row['fetched_at'],
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    /**
     * @param array<string, mixed> $shopData
     */
    public function replaceValidated(int $storeId, string $unicid, array $shopData): void
    {
        $this->requireStoreId($storeId);
        $unicid = trim($unicid);
        if ($unicid === '' || $shopData === []) {
            throw new PersistenceValidationException('Shop cache snapshot requires store scope, UNICID and non-empty shop data.');
        }

        try {
            $encoded = json_encode($shopData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new PersistenceValidationException('Shop cache snapshot cannot be encoded as JSON.', 0, $exception);
        }

        if ($encoded === '' || $encoded === '[]' || $encoded === '{}') {
            throw new PersistenceValidationException('Shop cache snapshot cannot be empty.');
        }

        $now = $this->clock->now();
        $fetchedAt = $this->clock->formatUtc($now);
        $expiresAt = $this->clock->formatUtc($now + SecurityConstants::SHOP_CACHE_TTL_SECONDS);
        $table = $this->tableName();

        $this->db->query(
            "INSERT INTO `{$table}`
                (`store_id`, `unicid`, `shop_data`, `fetched_at`, `expires_at`, `created_at`, `updated_at`)
             VALUES (
                " . (int) $storeId . ",
                '" . $this->db->escape($unicid) . "',
                '" . $this->db->escape($encoded) . "',
                '" . $this->db->escape($fetchedAt) . "',
                '" . $this->db->escape($expiresAt) . "',
                '" . $this->db->escape($fetchedAt) . "',
                '" . $this->db->escape($fetchedAt) . "'
             )
             ON DUPLICATE KEY UPDATE
                `shop_data` = VALUES(`shop_data`),
                `fetched_at` = VALUES(`fetched_at`),
                `expires_at` = VALUES(`expires_at`),
                `updated_at` = VALUES(`updated_at`)"
        );
    }

    public function deleteScoped(int $storeId, string $unicid): bool
    {
        $this->requireStoreId($storeId);
        $unicid = trim($unicid);
        if ($unicid === '') {
            return true;
        }

        $table = $this->tableName();
        $this->db->query(
            "DELETE FROM `{$table}`
             WHERE `store_id` = " . (int) $storeId . "
               AND `unicid` = '" . $this->db->escape($unicid) . "'"
        );

        return $this->db->countAffected() >= 0;
    }

    public function deleteExpiredBatch(int $limit = SecurityConstants::CLEANUP_DEFAULT_BATCH_SIZE): int
    {
        $limit = max(1, min(1000, $limit));
        $table = $this->tableName();
        $now = $this->clock->formatUtc($this->clock->now());
        $this->db->query(
            "DELETE FROM `{$table}`
             WHERE `expires_at` <= '" . $this->db->escape($now) . "'
             LIMIT " . (int) $limit
        );

        return $this->db->countAffected();
    }

    private function tableName(): string
    {
        return $this->db->getPrefix() . PersistenceTableNames::SHOP_CACHE;
    }

    private function requireStoreId(int $storeId): void
    {
        if ($storeId <= 0) {
            throw new PersistenceValidationException('Store scope is required.');
        }
    }
}
