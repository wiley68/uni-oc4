<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Short-lived mutex for Product, Cart and Checkout financing operations.
 */
final class OperationLockRepository
{
    private DbConnection $db;

    private PersistenceClock $clock;

    public function __construct(DbConnection $db, ?PersistenceClock $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?? new PersistenceClock();
    }

    public function acquire(int $storeId, string $entryPoint, string $operationKeyHash, string $ownerToken): bool
    {
        $this->requireIdentity($storeId, $entryPoint, $operationKeyHash, $ownerToken);

        $now = $this->clock->now();
        $expiresAt = $this->clock->formatUtc($now + SecurityConstants::OPERATION_LOCK_TTL_SECONDS);
        $createdAt = $this->clock->formatUtc($now);
        $table = $this->tableName();

        $this->db->query(
            "INSERT IGNORE INTO `{$table}`
                (`store_id`, `entry_point`, `operation_key_hash`, `owner_token`, `expires_at`, `created_at`, `updated_at`)
             VALUES (
                " . (int) $storeId . ",
                '" . $this->db->escape($entryPoint) . "',
                '" . $this->db->escape($operationKeyHash) . "',
                '" . $this->db->escape($ownerToken) . "',
                '" . $this->db->escape($expiresAt) . "',
                '" . $this->db->escape($createdAt) . "',
                '" . $this->db->escape($createdAt) . "'
             )"
        );

        if ($this->db->countAffected() === 1) {
            return true;
        }

        $nowSql = $this->db->escape($this->clock->formatUtc($now));
        $this->db->query(
            "UPDATE `{$table}`
             SET `owner_token` = '" . $this->db->escape($ownerToken) . "',
                 `expires_at` = '" . $this->db->escape($expiresAt) . "',
                 `updated_at` = '" . $this->db->escape($createdAt) . "'
             WHERE `store_id` = " . (int) $storeId . "
               AND `entry_point` = '" . $this->db->escape($entryPoint) . "'
               AND `operation_key_hash` = '" . $this->db->escape($operationKeyHash) . "'
               AND `expires_at` <= '" . $nowSql . "'"
        );

        return $this->db->countAffected() === 1;
    }

    public function release(int $storeId, string $entryPoint, string $operationKeyHash, string $ownerToken): bool
    {
        $this->requireIdentity($storeId, $entryPoint, $operationKeyHash, $ownerToken);
        $table = $this->tableName();

        $this->db->query(
            "DELETE FROM `{$table}`
             WHERE `store_id` = " . (int) $storeId . "
               AND `entry_point` = '" . $this->db->escape($entryPoint) . "'
               AND `operation_key_hash` = '" . $this->db->escape($operationKeyHash) . "'
               AND `owner_token` = '" . $this->db->escape($ownerToken) . "'"
        );

        return $this->db->countAffected() === 1;
    }

    public function heartbeat(int $storeId, string $entryPoint, string $operationKeyHash, string $ownerToken): bool
    {
        $this->requireIdentity($storeId, $entryPoint, $operationKeyHash, $ownerToken);

        $now = $this->clock->now();
        $expiresAt = $this->clock->formatUtc($now + SecurityConstants::OPERATION_LOCK_TTL_SECONDS);
        $updatedAt = $this->clock->formatUtc($now);
        $nowSql = $this->db->escape($this->clock->formatUtc($now));
        $table = $this->tableName();

        $this->db->query(
            "UPDATE `{$table}`
             SET `expires_at` = '" . $this->db->escape($expiresAt) . "',
                 `updated_at` = '" . $this->db->escape($updatedAt) . "'
             WHERE `store_id` = " . (int) $storeId . "
               AND `entry_point` = '" . $this->db->escape($entryPoint) . "'
               AND `operation_key_hash` = '" . $this->db->escape($operationKeyHash) . "'
               AND `owner_token` = '" . $this->db->escape($ownerToken) . "'
               AND `expires_at` > '" . $nowSql . "'"
        );

        return $this->db->countAffected() === 1;
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

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $storeId, string $entryPoint, string $operationKeyHash): ?array
    {
        $this->requireStoreAndEntryPoint($storeId, $entryPoint);
        PersistenceHashValidator::requireSha256Hex($operationKeyHash, 'operation_key_hash');

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT * FROM `{$table}`
             WHERE `store_id` = " . (int) $storeId . "
               AND `entry_point` = '" . $this->db->escape($entryPoint) . "'
               AND `operation_key_hash` = '" . $this->db->escape($operationKeyHash) . "'
             LIMIT 1"
        );

        if (!is_object($result) || $result->num_rows !== 1) {
            return null;
        }

        return $result->row;
    }

    private function tableName(): string
    {
        return $this->db->getPrefix() . PersistenceTableNames::OPERATION_LOCK;
    }

    private function requireIdentity(int $storeId, string $entryPoint, string $operationKeyHash, string $ownerToken): void
    {
        $this->requireStoreAndEntryPoint($storeId, $entryPoint);
        PersistenceHashValidator::requireSha256Hex($operationKeyHash, 'operation_key_hash');
        if (!LockOwnerTokenGenerator::isValidFormat($ownerToken)) {
            throw new PersistenceValidationException('Lock owner token must be 32 lowercase hex characters.');
        }
    }

    private function requireStoreAndEntryPoint(int $storeId, string $entryPoint): void
    {
        OpenCartStoreScope::require($storeId);
        if (!OperationEntryPoint::isValid($entryPoint)) {
            throw new PersistenceValidationException('Unsupported operation lock entry point.');
        }
    }
}
