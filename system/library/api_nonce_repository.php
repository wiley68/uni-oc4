<?php

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Replay-protection nonce claims — stores sha256(nonce), never raw nonce.
 */
final class ApiNonceRepository
{
    private DbConnection $db;

    private PersistenceClock $clock;

    public function __construct(DbConnection $db, ?PersistenceClock $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?? new PersistenceClock();
    }

    /**
     * Atomic claim-once. Returns true only when this request inserted the nonce row.
     */
    public function claim(int $storeId, string $unicid, string $nonce): bool
    {
        $this->requireStoreId($storeId);
        $unicid = trim($unicid);
        if ($unicid === '' || !self::isValidNonceFormat($nonce)) {
            throw new PersistenceValidationException('Nonce claim requires store scope, UNICID and a 64-char lowercase hex nonce.');
        }

        $now = $this->clock->now();
        $nonceHash = hash('sha256', $nonce);
        $usedAt = $this->clock->formatUtc($now);
        $expiresAt = $this->clock->formatUtc($now + SecurityConstants::NONCE_RETENTION_SECONDS);
        $table = $this->tableName();

        try {
            $this->db->query(
                "INSERT INTO `{$table}`
                    (`store_id`, `unicid`, `nonce_hash`, `used_at`, `expires_at`)
                 VALUES (
                    " . (int) $storeId . ",
                    '" . $this->db->escape($unicid) . "',
                    '" . $this->db->escape($nonceHash) . "',
                    '" . $this->db->escape($usedAt) . "',
                    '" . $this->db->escape($expiresAt) . "'
                 )"
            );
        } catch (\Throwable $exception) {
            if (self::isDuplicateKeyError($exception)) {
                return false;
            }

            throw new PersistenceException('Nonce claim failed.', 0, $exception);
        }

        if ($this->db->countAffected() === 1) {
            $this->pruneExpiredIfDue();

            return true;
        }

        return false;
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

    private function pruneExpiredIfDue(): void
    {
        $this->deleteExpiredBatch(10);
    }

    public static function hashNonce(string $nonce): string
    {
        return hash('sha256', $nonce);
    }

    public static function isValidNonceFormat(string $nonce): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $nonce);
    }

    private function tableName(): string
    {
        return $this->db->getPrefix() . PersistenceTableNames::API_NONCE;
    }

    private function requireStoreId(int $storeId): void
    {
        OpenCartStoreScope::require($storeId);
    }

    private static function isDuplicateKeyError(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'duplicate')
            || str_contains($message, '1062')
            || str_contains($message, 'unique');
    }
}
