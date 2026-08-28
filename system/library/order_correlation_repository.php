<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Module-owned attempt↔order correlation for crash recovery between addOrder() and attempt.attachOrder().
 *
 * Durable fact after addOrder(): row in {@see PersistenceTableNames::ORDER_CORRELATION}.
 */
final class OrderCorrelationRepository implements OrderCorrelationStoreInterface
{
    private DbConnection $db;

    private PersistenceClock $clock;

    public function __construct(DbConnection $db, ?PersistenceClock $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?? new PersistenceClock();
    }

    public function linkCreatedOrder(int $storeId, int $attemptId, int $orderId): void
    {
        if ($storeId <= 0 || $attemptId <= 0 || $orderId <= 0) {
            throw new PersistenceValidationException('Store, attempt and order identifiers are required for correlation.');
        }

        $table = $this->tableName();
        $now = $this->clock->formatUtc($this->clock->now());

        try {
            $this->db->query(
                "INSERT INTO `{$table}`
                    (`store_id`, `attempt_id`, `order_id`, `created_at`, `updated_at`)
                 VALUES (
                    " . (int) $storeId . ",
                    " . (int) $attemptId . ",
                    " . (int) $orderId . ",
                    '" . $this->db->escape($now) . "',
                    '" . $this->db->escape($now) . "'
                 )"
            );
        } catch (\Throwable $exception) {
            if (!self::isDuplicateKeyError($exception)) {
                throw new PersistenceException('Order correlation insert failed.', 0, $exception);
            }

            $existing = $this->findOrderIdByAttempt($storeId, $attemptId);
            if ($existing === $orderId) {
                return;
            }

            throw new PersistenceConflictException('The order is already correlated to another financing attempt.');
        }
    }

    public function findOrderIdByAttempt(int $storeId, int $attemptId): ?int
    {
        if ($storeId <= 0 || $attemptId <= 0) {
            return null;
        }

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT `order_id` FROM `{$table}`
             WHERE `store_id` = " . (int) $storeId . "
               AND `attempt_id` = " . (int) $attemptId . "
             LIMIT 1"
        );

        if (!is_object($result) || $result->num_rows !== 1) {
            return null;
        }

        $orderId = (int) ($result->row['order_id'] ?? 0);

        return $orderId > 0 ? $orderId : null;
    }

    public function findAttemptIdByOrder(int $storeId, int $orderId): ?int
    {
        if ($storeId <= 0 || $orderId <= 0) {
            return null;
        }

        $table = $this->tableName();
        $result = $this->db->query(
            "SELECT `attempt_id` FROM `{$table}`
             WHERE `store_id` = " . (int) $storeId . "
               AND `order_id` = " . (int) $orderId . "
             LIMIT 1"
        );

        if (!is_object($result) || $result->num_rows !== 1) {
            return null;
        }

        $attemptId = (int) ($result->row['attempt_id'] ?? 0);

        return $attemptId > 0 ? $attemptId : null;
    }

    private function tableName(): string
    {
        return $this->db->getPrefix() . PersistenceTableNames::ORDER_CORRELATION;
    }

    private static function isDuplicateKeyError(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'duplicate')
            || str_contains($message, '1062')
            || str_contains($message, 'unique');
    }
}
