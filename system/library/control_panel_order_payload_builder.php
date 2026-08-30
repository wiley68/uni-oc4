<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * FinancingSnapshot + LocalOrder → Control Panel POST /orders body (PS9 parity).
 *
 * Source of truth after local_order_prepared: ValidatedFinancingSubmission (frozen at submit)
 * and/or previously persisted attempt.cp_payload. Do not rebuild from live cart/product.
 */
final class ControlPanelOrderPayloadBuilder
{
    /**
     * @param array<string, mixed> $shop CP shop snapshot
     * @return array<string, mixed>
     */
    public function build(ValidatedFinancingSubmission $submission, int $localOrderId, array $shop): array
    {
        $customer = $submission->customer;
        $billing = $submission->billingAddress;
        $shipping = $submission->shippingAddress ?? $billing;
        $calc = $submission->financingCalculation;

        $ids = [];
        $names = [];
        $quantities = [];
        foreach ($submission->orderDraft->products as $product) {
            $ids[] = (int) ($product['product_id'] ?? 0);
            $names[] = str_replace('_', '-', (string) ($product['name'] ?? ''));
            $quantities[] = max(1, (int) ($product['quantity'] ?? 1));
        }

        $name = trim($customer->firstname . ' ' . $customer->lastname);
        $address = $this->formatAddress($billing);
        $address2 = $this->formatAddress($shipping);
        if ($address2 === '') {
            $address2 = $address;
        }
        if ($address2 === '') {
            $address2 = '-';
        }

        $currency = strtoupper(trim($submission->orderDraft->currencyCode));
        if ($currency !== 'BGN' && $currency !== 'EUR') {
            $currency = 'BGN';
        }

        $payload = [
            'order_id' => substr((string) $localOrderId, 0, 13),
            'name' => substr($name, 0, 65),
            'phone' => substr($customer->telephone, 0, 45),
            'email' => substr($customer->email, 0, 128),
            'address' => substr($address, 0, 256),
            'address2' => substr($address2, 0, 256),
            'price' => round((float) $calc->financedAmount, 2),
            'vnoska' => round((float) $calc->monthlyInstallment, 2),
            'gpr' => round((float) $calc->gpr, 2),
            'vnoski' => (int) $calc->scheme->months,
            'parva' => round((float) $calc->firstInstallment->amount, 2),
            'products_id' => implode('_', $ids),
            'products_name' => substr(implode('_', $names), 0, 255),
            'products_q' => implode('_', $quantities),
            'type_client' => !empty($shop['_is_mobile']) ? 0 : 1,
            'currency' => $currency,
            'version' => ModuleConstants::VERSION,
        ];

        // Phase 10B: omit status/status_id for all shops. CP StoreOrderRequest defaults to
        // "Създаден в КП Банка" / cp_sent. bank_sent_process1|2 and bank_send_failed_smartucf
        // are Phase 11 bank-side outcomes only — CP create ≠ sent to bank.

        return $payload;
    }

    private function formatAddress(FinancingAddressData $address): string
    {
        return trim(implode(', ', array_filter([
            (string) $address->address1,
            (string) $address->address2,
            (string) $address->postcode,
            (string) $address->city,
            (string) $address->country,
        ], static fn(string $part): bool => $part !== '')));
    }
}
