<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Checkout gateway — validates and reuses native session.order_id order.
 * Never calls addOrder().
 */
final class CheckoutExistingOrderGateway implements OpenCartOrderGatewayInterface
{
    public function __construct(
        private CheckoutOrderModelPort $orders,
        private OpenCartOrderVerifier $verifier,
        private FinancingOrderStatusPolicy $statusPolicy
    ) {
    }

    public function materialize(
        ValidatedFinancingSubmission $submission,
        FinancingAttemptContext $attempt
    ): CreatedOpenCartOrder {
        if ($submission->entryPoint !== OperationEntryPoint::CHECKOUT) {
            throw new OrderMaterializationException('Checkout gateway received a non-checkout submission.');
        }

        $orderId = $submission->existingOrderId;
        if ($orderId === null || $orderId <= 0) {
            throw new OrderMaterializationException('Checkout submission requires an existing order identifier.');
        }

        $order = $this->orders->getOrder($orderId);
        if ($order === []) {
            throw new OrderMaterializationException('Checkout order was not found.');
        }
        if ((int) ($order['store_id'] ?? 0) !== $submission->storeId) {
            throw new OrderMaterializationException('Checkout order store scope mismatch.');
        }

        $paymentMethod = $order['payment_method'] ?? [];
        if (is_string($paymentMethod)) {
            $decoded = json_decode($paymentMethod, true);
            $paymentMethod = is_array($decoded) ? $decoded : ['code' => ''];
        }
        if (!PaymentIdentity::matchesStoredPayment($paymentMethod)) {
            throw new OrderMaterializationException('Checkout order payment method is not UniCredit.');
        }

        $statusId = (int) ($order['order_status_id'] ?? -1);
        if (!$this->statusPolicy->isCheckoutReuseAllowedStatus($statusId)) {
            throw new OrderMaterializationException('Checkout order is not in a financing-ready status.');
        }

        $products = $this->orders->getProducts($orderId);
        if ($products === []) {
            throw new OrderMaterializationException('Checkout order has no products.');
        }

        $created = new CreatedOpenCartOrder(
            $orderId,
            (int) $order['store_id'],
            (float) ($order['total'] ?? 0.0),
            (string) ($order['currency_code'] ?? ''),
            $products,
            $this->orders->getTotals($orderId),
            (string) ($paymentMethod['code'] ?? ''),
            $statusId,
            false
        );

        $this->verifier->verify($created, $submission, $submission->orderDraft);

        return $created;
    }
}
