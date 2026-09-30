<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Rebuild financing inputs from a proven native order and frozen presentation. */
final class DurableEurResumeHydrator
{
    public function __construct(private DbConnection $db)
    {
    }

    public function hydrate(int $attemptId, ValidatedFinancingSubmission $submission, DurableEurOrderProof $proof, bool $allowProcessTwo = false): void
    {
        if ($submission->submissionSource !== 'resume') {
            return;
        }
        $prefix = $this->db->getPrefix();
        $attempt = $this->one("SELECT `unicid`, `leasing_presentation_json` FROM `{$prefix}" . PersistenceTableNames::FINANCING_ATTEMPT . "` WHERE `attempt_id` = {$attemptId} LIMIT 1");
        $order = $this->one("SELECT * FROM `{$prefix}order` WHERE `order_id` = {$proof->orderId} LIMIT 1");
        $linesResult = $this->db->query("SELECT `product_id`, `name`, `model`, `quantity`, `price`, `total`, `tax` FROM `{$prefix}order_product` WHERE `order_id` = {$proof->orderId} ORDER BY `order_product_id` ASC");
        $lines = is_object($linesResult) && isset($linesResult->rows) && is_array($linesResult->rows)
            ? $linesResult->rows : [];
        if ($attempt === null || $order === null || $lines === []
            || (int) ($order['store_id'] ?? -1) !== $proof->storeId
            || (int) ($order['currency_id'] ?? 0) !== $proof->currencyId
            || (string) ($order['currency_code'] ?? '') !== 'EUR'
            || abs((float) ($order['currency_value'] ?? 0) - $proof->currencyValue) > 0.000001
            || abs((float) ($order['total'] ?? 0) - $proof->baseTotal) > 0.02) {
            throw DurableEurOrderGuard::failure();
        }
        $unicid = trim((string) ($attempt['unicid'] ?? ''));
        $raw = $attempt['leasing_presentation_json'] ?? null;
        if ($unicid === '' || !is_string($raw) || $raw === '') {
            throw DurableEurOrderGuard::failure();
        }
        try {
            $snapshot = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw DurableEurOrderGuard::failure();
        }
        if (!is_array($snapshot)
            || (int) ($snapshot['shop_order_id'] ?? 0) !== $proof->orderId
            || (!$allowProcessTwo && !empty($snapshot['process2']))
            || (int) ($snapshot['months'] ?? 0) <= 0
            || trim((string) ($snapshot['kop_code'] ?? '')) === '') {
            throw DurableEurOrderGuard::failure();
        }
        foreach (['financed_amount', 'first_installment', 'monthly_installment', 'total_payable', 'glp', 'gpr'] as $field) {
            if (!is_numeric($snapshot[$field] ?? null) || !is_finite((float) $snapshot[$field])) {
                throw DurableEurOrderGuard::failure();
            }
        }
        if (abs(((float) $snapshot['financed_amount'] + (float) $snapshot['first_installment']) - $proof->eurTotal) > 0.02
            || (float) $snapshot['monthly_installment'] <= 0.0) {
            throw DurableEurOrderGuard::failure();
        }
        $adapter = new CheckoutOrderCustomerAdapter();
        $resolved = $adapter->fromCheckoutContext($order);
        if ($resolved['missing'] !== []) {
            throw DurableEurOrderGuard::failure();
        }
        try {
            $customer = (new CheckoutCustomerValidator())->validate(
                $resolved['input'],
                (int) ($order['customer_group_id'] ?? 0),
                (int) ($order['customer_id'] ?? 0)
            )['customer'];
        } catch (ProductFinancingFlowException) {
            throw DurableEurOrderGuard::failure();
        }
        $products = [];
        foreach ($lines as $line) {
            $quantity = (int) ($line['quantity'] ?? 0);
            $total = (float) ($line['total'] ?? 0);
            if ((int) ($line['product_id'] ?? 0) <= 0 || $quantity <= 0
                || !is_finite($total) || $total <= 0.0 || trim((string) ($line['name'] ?? '')) === '') {
                throw DurableEurOrderGuard::failure();
            }
            $products[] = [
                'product_id' => (int) $line['product_id'],
                'name' => (string) $line['name'],
                'model' => (string) ($line['model'] ?? ''),
                'quantity' => $quantity,
                'price' => (float) ($line['price'] ?? 0),
                'total' => $total,
                'tax' => (float) ($line['tax'] ?? 0),
            ];
        }
        $submission->customer = $customer;
        $submission->billingAddress = $adapter->billingAddressFromResolved($resolved['input'], $customer);
        $submission->shippingAddress = $adapter->shippingAddressFromOrder($order, $submission->billingAddress);
        $scheme = new AvailableScheme(
            'standard', (string) $snapshot['kop_code'], (int) $snapshot['months'], 0, null, []
        );
        $submission->financingCalculation = new CalculationResult(
            $scheme,
            $proof->eurTotal,
            new FirstInstallmentState((float) $snapshot['first_installment'], false, (float) $snapshot['first_installment'] > 0.0),
            (float) $snapshot['financed_amount'],
            (float) $snapshot['monthly_installment'],
            (float) $snapshot['total_payable'],
            (float) $snapshot['glp'],
            (float) $snapshot['gpr']
        );
        $submission->orderDraft->products = $products;
        $submission->orderDraft->orderTotal = $proof->baseTotal;
        $submission->orderDraft->currencyCode = 'EUR';
        $submission->orderDraft->currencyId = $proof->currencyId;
        $submission->orderDraft->currencyValue = $proof->currencyValue;
        $submission->shopUnicid = $unicid;
    }

    /** @return array<string, mixed>|null */
    private function one(string $sql): ?array
    {
        $result = $this->db->query($sql);
        return is_object($result) && (int) ($result->num_rows ?? 0) === 1 && is_array($result->row ?? null)
            ? $result->row : null;
    }
}
