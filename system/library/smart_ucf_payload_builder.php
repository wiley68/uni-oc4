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
        $calculation = $submission->financingCalculation;
        $address = $submission->billingAddress;
        $deliveryAddress = trim(implode(', ', array_filter([
            $address->address1,
            $address->address2,
            $address->city,
            $address->postcode,
        ], static fn(string $value): bool => trim($value) !== '')));

        $payload = [
            'user' => (string) ($shop['uni_user'] ?? ''),
            'pass' => (string) ($shop['uni_password'] ?? ''),
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
            'items' => $this->buildItems($submission->orderDraft->products, $shop, $submission->orderDraft->currencyCode),
        ];

        foreach (array_keys($payload) as $key) {
            if (preg_match('/egn|phone2/i', (string) $key)) {
                throw new \LogicException('Sensitive Process 2 field leaked into SmartUCF payload.');
            }
        }

        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $shop
     * @return list<array<string, mixed>>
     */
    private function buildItems(array $lines, array $shop, string $currencyIso): array
    {
        $items = [];
        foreach ($lines as $line) {
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $unitPrice = ((float) ($line['total'] ?? $line['price'] ?? 0)) / $quantity;
            $uniEur = (int) ($shop['uni_eur'] ?? 0);
            if ($uniEur === 1 && strtoupper($currencyIso) === 'EUR') {
                $unitPrice *= 1.95583;
            } elseif (in_array($uniEur, [2, 3], true) && strtoupper($currencyIso) === 'BGN') {
                $unitPrice /= 1.95583;
            }
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
