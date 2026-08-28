<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Post-addOrder verification against authoritative draft/submission data. */
final class OpenCartOrderVerifier
{
    public function verify(
        CreatedOpenCartOrder $created,
        ValidatedFinancingSubmission $submission,
        OrderDraft $expectedDraft
    ): void {
        if ($created->orderId <= 0) {
            throw new OrderMaterializationException('Materialized order identifier is invalid.');
        }
        if ($created->storeId !== $submission->storeId) {
            throw new OrderMaterializationException('Materialized order store scope mismatch.');
        }
        if ($created->currencyCode !== $expectedDraft->currencyCode) {
            throw new OrderMaterializationException('Materialized order currency mismatch.');
        }
        if (!PaymentIdentity::matchesStoredPayment($created->paymentMethodCode)) {
            throw new OrderMaterializationException('Materialized order payment identity mismatch.');
        }
        if ($created->products === []) {
            throw new OrderMaterializationException('Materialized order has no products.');
        }
        if (abs($created->total - $expectedDraft->orderTotal) > 0.01) {
            throw new OrderMaterializationException('Materialized order total mismatch.');
        }

        $this->verifyProducts($created->products, $expectedDraft->products);
    }

    /**
     * @param list<array<string, mixed>> $actualProducts
     * @param list<array<string, mixed>> $expectedProducts
     */
    private function verifyProducts(array $actualProducts, array $expectedProducts): void
    {
        if (count($actualProducts) !== count($expectedProducts)) {
            throw new OrderMaterializationException('Materialized order product count mismatch.');
        }

        foreach ($expectedProducts as $index => $expected) {
            $actual = $actualProducts[$index] ?? null;
            if (!is_array($actual)) {
                throw new OrderMaterializationException('Materialized order product row missing.');
            }
            if ((int) ($actual['product_id'] ?? 0) !== (int) ($expected['product_id'] ?? 0)) {
                throw new OrderMaterializationException('Materialized order product identity mismatch.');
            }
            if ((int) ($actual['quantity'] ?? 0) !== (int) ($expected['quantity'] ?? 0)) {
                throw new OrderMaterializationException('Materialized order product quantity mismatch.');
            }
        }
    }
}
