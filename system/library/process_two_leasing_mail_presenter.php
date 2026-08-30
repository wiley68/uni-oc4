<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Builds Process 2 leasing email bodies (PS9/Woo audience split).
 */
final class ProcessTwoLeasingMailPresenter
{
    public const CUSTOMER_CONFIRMATION =
        'Очаквайте контакт за потвърждаване на направената от Вас заявка.';

    /**
     * @param array<string, mixed> $orderContext
     * @return list<array{label: string, value: string}>
     */
    public function adminRows(array $orderContext, ?ProcessTwoSensitiveData $sensitive): array
    {
        $rows = $this->commonRows($orderContext);
        $rows[] = [
            'label' => 'Статус към банката',
            'value' => BankStatus::LABEL_SENT_PROCESS2,
        ];
        if ($sensitive !== null) {
            $rows[] = ['label' => 'ЕГН', 'value' => $sensitive->egn];
            $rows[] = ['label' => 'Втори телефон', 'value' => $sensitive->phone2];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $orderContext
     * @return list<array{label: string, value: string}>
     */
    public function customerRows(array $orderContext): array
    {
        $rows = $this->commonRows($orderContext);
        $rows[] = [
            'label' => 'Статус към банката',
            'value' => BankStatus::LABEL_SENT_PROCESS2,
        ];
        $rows[] = [
            'label' => 'Съобщение',
            'value' => self::CUSTOMER_CONFIRMATION,
        ];

        return $rows;
    }

    /**
     * @param list<array{label: string, value: string}> $rows
     */
    public function renderHtml(array $rows): string
    {
        $html = '<h2>УниКредит лизинг</h2><table>';
        foreach ($rows as $row) {
            $html .= '<tr><th style="text-align:left;padding:4px 12px 4px 0;">'
                . htmlspecialchars($row['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</th><td>'
                . htmlspecialchars($row['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</td></tr>';
        }
        $html .= '</table>';

        return $html;
    }

    /**
     * @param list<array{label: string, value: string}> $rows
     */
    public function renderText(array $rows): string
    {
        $lines = ["УниКредит лизинг", ''];
        foreach ($rows as $row) {
            $lines[] = $row['label'] . ': ' . $row['value'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $orderContext
     * @return list<array{label: string, value: string}>
     */
    private function commonRows(array $orderContext): array
    {
        $rows = [];
        if (!empty($orderContext['order_id'])) {
            $rows[] = ['label' => 'Поръчка', 'value' => (string) $orderContext['order_id']];
        }
        if (!empty($orderContext['customer_name'])) {
            $rows[] = ['label' => 'Клиент', 'value' => (string) $orderContext['customer_name']];
        }
        if (!empty($orderContext['scheme_label'])) {
            $rows[] = ['label' => 'Схема', 'value' => (string) $orderContext['scheme_label']];
        }
        if (!empty($orderContext['monthly_amount'])) {
            $rows[] = ['label' => 'Месечна вноска', 'value' => (string) $orderContext['monthly_amount']];
        }

        return $rows;
    }
}
