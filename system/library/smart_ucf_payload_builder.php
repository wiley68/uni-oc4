<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class SmartUcfPayloadBuilder
{
    /**
     * @param array<string, mixed> $shop
     * @return array<string, mixed>
     */
    public function build(ValidatedFinancingSubmission $submission, array $shop, int $localOrderId): array
    {
        $proof = $submission->eurOrderProof;
        if ($proof === null || $proof->orderId !== $localOrderId
            || !(new CurrencyGate())->supports($submission->orderDraft->currencyCode)
            || $submission->orderDraft->currencyId !== $proof->currencyId
            || abs($submission->orderDraft->currencyValue - $proof->currencyValue) > 0.000001
            || abs($submission->orderDraft->orderTotal - $proof->baseTotal) > 0.02
            || abs($submission->financingCalculation->price - $proof->eurTotal) > 0.02) {
            throw DurableEurOrderGuard::failure();
        }
        $calculation = $submission->financingCalculation;
        $address = $submission->billingAddress;
        $deliveryAddress = trim(implode(', ', array_filter([
            $address->address1,
            $address->address2,
            $address->city,
            $address->postcode,
        ], static fn(string $value): bool => trim($value) !== '')));

        $payload = [
            'user' => trim((string) ($shop['uni_user'] ?? '')),
            'pass' => trim((string) ($shop['uni_password'] ?? '')),
            'orderNo' => (string) $localOrderId,
            'clientFirstName' => $this->clean($submission->customer->firstname),
            'clientLastName' => $this->clean($submission->customer->lastname),
            'clientPhone' => $this->clean($submission->customer->telephone),
            'clientEmail' => $this->clean($submission->customer->email),
            'clientDeliveryAddress' => $this->clean($deliveryAddress),
            'onlineProductCode' => $calculation->scheme->kopCode,
            'totalPrice' => $this->formatAmount($calculation->price),
            'initialPayment' => $this->formatAmount($calculation->firstInstallment->amount),
            'installmentCount' => $calculation->scheme->months,
            'monthlyPayment' => $this->formatAmount($calculation->monthlyInstallment),
            'items' => $this->buildItems($submission->orderDraft->products, $proof),
        ];

        if ($payload['user'] === '' || $payload['pass'] === '') {
            throw new \InvalidArgumentException('SmartUCF credentials are required before payload build.');
        }

        foreach (array_keys($payload) as $key) {
            if (preg_match('/egn|phone2/i', (string) $key)) {
                throw new \LogicException('Sensitive Process 2 field leaked into SmartUCF payload.');
            }
        }

        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function buildItems(array $lines, DurableEurOrderProof $proof): array
    {
        $items = [];
        foreach ($lines as $line) {
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            // OpenCart total is the net line amount; tax is per unit, both in base units.
            // Merchandise excludes shipping/order-level adjustments in totalPrice.
            $unitPrice = EurFinancingAmount::fromOrder(
                (isset($line['total']) ? (float) $line['total'] / $quantity : (float) ($line['price'] ?? 0))
                    + (float) ($line['tax'] ?? 0),
                'EUR',
                $proof->currencyId,
                $proof->currencyValue
            );
            $items[] = [
                'name' => $this->clean((string) ($line['name'] ?? '')),
                'code' => (int) ($line['product_id'] ?? 0),
                'type' => 0,
                'count' => $quantity,
                'singlePrice' => $this->formatAmount($unitPrice),
            ];
        }

        return $items;
    }

    private function formatAmount(float $amount): string
    {
        return number_format(abs($amount), 2, '.', '');
    }

    private function clean(string $value): string
    {
        return str_replace(["'", "\u{2019}"], '', trim($value));
    }
}
