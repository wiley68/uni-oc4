<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Authorizes inbound CP order references against financing attempts (store-scoped).
 *
 * Fail-closed cardinality on (store_id, order_id, unicid): 0 → null; 1 → attempt; 2+ → ambiguous.
 * Wrong UNICID for the same store/order → null (not found), never a cross-shop disclosure.
 * No payment-method fallback.
 */
final class FinancingOrderResolver
{
    public function __construct(private DbConnection $db, private ?PersistenceClock $clock = null)
    {
        $this->clock ??= new PersistenceClock();
    }

    /**
     * @return array{attempt: array<string, mixed>, order_id: int}|null
     */
    public function resolve(int $storeId, string $unicid, string $orderIdString): ?array
    {
        OpenCartStoreScope::require($storeId);
        $unicid = trim($unicid);
        $orderIdString = trim($orderIdString);

        if ($unicid === '' || $orderIdString === '' || !ctype_digit($orderIdString)) {
            return null;
        }

        $orderId = (int) $orderIdString;
        if ($orderId <= 0 || (string) $orderId !== $orderIdString) {
            return null;
        }

        $table = $this->db->getPrefix() . PersistenceTableNames::FINANCING_ATTEMPT;
        $result = $this->db->query(
            "SELECT *
             FROM `{$table}`
             WHERE `store_id` = " . (int) $storeId . "
               AND `order_id` = " . (int) $orderId . "
               AND `unicid` = '" . $this->db->escape($unicid) . "'"
        );

        if (!is_object($result) || (int) $result->num_rows === 0) {
            return null;
        }

        if ((int) $result->num_rows > 1) {
            throw new FinancingOrderAmbiguousException(
                'Multiple financing attempts match this store order id.'
            );
        }

        /** @var array<string, mixed> $row */
        $row = $result->row;

        return [
            'attempt' => $row,
            'order_id' => $orderId,
        ];
    }
}
