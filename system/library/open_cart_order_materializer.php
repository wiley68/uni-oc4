<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Product/Cart shared local order materialization via native addOrder().
 */
final class OpenCartOrderMaterializer
{
    public function __construct(
        private CheckoutOrderModelPort $orders,
        private OpenCartOrderDataBuilder $builder,
        private OpenCartOrderVerifier $verifier
    ) {
    }

    public function materializeNew(
        ValidatedFinancingSubmission $submission,
        FinancingAttemptContext $attempt
    ): CreatedOpenCartOrder {
        $marker = OrderRecoveryMarker::forAttempt($attempt->storeId(), $attempt->attemptId());
        $existingId = $this->orders->findOrderIdByRecoveryMarker($attempt->storeId(), $marker);
        if ($existingId !== null) {
            return $this->loadVerified($existingId, $submission, true);
        }

        $orderData = $this->builder->build($submission->orderDraft, $marker);
        $orderId = $this->orders->addOrder($orderData);
        if ($orderId <= 0) {
            throw new OrderMaterializationException('OpenCart addOrder did not return a valid order identifier.');
        }

        return $this->loadVerified($orderId, $submission, false);
    }

    public function loadVerified(
        int $orderId,
        ValidatedFinancingSubmission $submission,
        bool $recovered
    ): CreatedOpenCartOrder {
        $order = $this->orders->getOrder($orderId);
        if ($order === []) {
            throw new OrderMaterializationException('Materialized order could not be loaded.');
        }

        $paymentMethod = $order['payment_method'] ?? [];
        if (is_string($paymentMethod)) {
            $decoded = json_decode($paymentMethod, true);
            $paymentMethod = is_array($decoded) ? $decoded : ['code' => ''];
        }

        $products = $this->orders->getProducts($orderId);
        $created = new CreatedOpenCartOrder(
            $orderId,
            (int) ($order['store_id'] ?? 0),
            (float) ($order['total'] ?? 0.0),
            (string) ($order['currency_code'] ?? ''),
            $products,
            $this->orders->getTotals($orderId),
            (string) ($paymentMethod['code'] ?? ''),
            (int) ($order['order_status_id'] ?? 0),
            $recovered
        );

        $this->verifier->verify($created, $submission, $submission->orderDraft);

        return $created;
    }
}
