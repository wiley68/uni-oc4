<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Persists CP → module bank status callbacks scoped by store + local order.
 */
final class OrderBankStatusRepository
{
    private DbConnection $db;

    private PersistenceClock $clock;

    public function __construct(DbConnection $db, ?PersistenceClock $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?? new PersistenceClock();
    }

    /**
     * @return array{order_id: string, oc_order_id: int, status: string, status_id: string, oc_order_state_changed: bool}|null
     */
    public function updateByOrderIdentifier(int $storeId, string $orderReference, string $statusId, string $statusLabel): ?array
    {
        OpenCartStoreScope::require($storeId);
        $orderReference = trim($orderReference);
        $statusId = trim($statusId);
        $statusLabel = trim($statusLabel);
        if ($orderReference === '' || $statusId === '') {
            return null;
        }

        $orderId = $this->resolveAuthorizedOrderId($storeId, $orderReference);
        if ($orderId === null) {
            return null;
        }

        $updatedAt = $this->clock->formatUtc($this->clock->now());
        $table = $this->tableName();
        $this->db->query(
            "INSERT INTO `{$table}`
                (`store_id`, `order_id`, `order_reference`, `status_id`, `status_label`, `updated_at`)
             VALUES (
                " . (int) $storeId . ",
                " . (int) $orderId . ",
                '" . $this->db->escape($orderReference) . "',
                '" . $this->db->escape($statusId) . "',
                '" . $this->db->escape($statusLabel) . "',
                '" . $this->db->escape($updatedAt) . "'
             )
             ON DUPLICATE KEY UPDATE
                `order_reference` = VALUES(`order_reference`),
                `status_id` = VALUES(`status_id`),
                `status_label` = VALUES(`status_label`),
                `updated_at` = VALUES(`updated_at`)"
        );

        return [
            'order_id' => $orderReference,
            'oc_order_id' => $orderId,
            'status' => $statusLabel,
            'status_id' => $statusId,
            'oc_order_state_changed' => false,
        ];
    }

    private function resolveAuthorizedOrderId(int $storeId, string $orderReference): ?int
    {
        if (!ctype_digit($orderReference)) {
            return null;
        }

        $orderId = (int) $orderReference;
        if ($orderId <= 0) {
            return null;
        }

        $attempts = new FinancingAttemptRepository($this->db, $this->clock);
        $attempt = $attempts->findByOrderId($storeId, $orderId);
        if ($attempt !== null) {
            return $orderId;
        }

        // Fallback: UniCredit payment method on the OpenCart order row (store-scoped).
        $orderTable = $this->db->getPrefix() . 'order';
        $result = $this->db->query(
            "SELECT `order_id`, `store_id`, `payment_method`
             FROM `{$orderTable}`
             WHERE `order_id` = " . (int) $orderId . "
             LIMIT 1"
        );
        if (!is_object($result) || $result->num_rows !== 1) {
            return null;
        }

        $row = $result->row;
        if ((int) ($row['store_id'] ?? -1) !== $storeId) {
            return null;
        }

        $payment = $row['payment_method'] ?? '';
        if (is_string($payment) && $payment !== '') {
            $decoded = json_decode($payment, true);
            $code = is_array($decoded) ? (string) ($decoded['code'] ?? '') : $payment;
            if (PaymentIdentity::matchesStoredPayment(is_array($decoded) ? $decoded : $code)) {
                return $orderId;
            }
        }

        return null;
    }

    private function tableName(): string
    {
        return $this->db->getPrefix() . PersistenceTableNames::ORDER_BANK_STATUS;
    }
}
