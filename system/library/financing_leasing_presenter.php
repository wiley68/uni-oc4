<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Shared UniCredit leasing rows for Thank You / emails / Admin (Woo + PS9 labels).
 */
final class FinancingLeasingPresenter
{
    public const TITLE = 'УниКредит лизинг';
    public const ADMIN_TITLE = 'УниКредит — кредитна заявка';
    public const PROCESS2_MESSAGE = 'Очаквайте контакт за потвърждаване на направената от Вас заявка.';

    /** PS9 order_confirmation_smartucf_failure.tpl — customer-safe terminal Process 1 failure. */
    public const SMARTUCF_TERMINAL_FAILURE_MESSAGE =
    'Поръчката Ви е регистрирана успешно в магазина, но заявката за финансиране не беше приета/стартирана успешно от банковата система. '
        . 'Не изпращайте поръчката повторно. При необходимост търговецът ще се свърже с Вас.';

    public const LABEL_BANK_STATUS = 'Статус към банката';
    public const LABEL_CP_INTERNAL_ID = 'КП поръчка (ID)';
    public const LABEL_CP_SHOP_ORDER_ID = 'КП shop order_id';
    public const LABEL_MONTHS = 'Срок (месеци)';
    public const LABEL_KOP = 'КОП';
    public const LABEL_FIRST = 'Първоначална вноска';
    public const LABEL_LOAN = 'Сума на заема';
    public const LABEL_MONTHLY = 'Месечна вноска';
    public const LABEL_TOTAL = 'Обща дължима сума';
    public const LABEL_GLP_GPR = 'ГЛП / ГПР';
    public const LABEL_MESSAGE = 'Съобщение';
    public const LABEL_EGN = 'ЕГН';
    public const LABEL_PHONE2 = 'Втори телефон';

    /**
     * @return list<array{label: string, value: string}>
     */
    public function rows(
        FinancingPresentationSnapshot $snapshot,
        string $bankStatusLabel,
        string $audience,
        ?ProcessTwoSensitiveData $sensitive = null
    ): array {
        $rows = [];
        $status = trim($bankStatusLabel);
        // Do not invent bank_sent_* labels when durable status is absent
        // (native order email fires at interim addHistory, before CP handoff).
        if ($status !== '') {
            $rows[] = ['label' => self::LABEL_BANK_STATUS, 'value' => $status];
        }

        if ($snapshot->controlPanelOrderId !== null && $snapshot->controlPanelOrderId > 0) {
            $rows[] = [
                'label' => self::LABEL_CP_INTERNAL_ID,
                'value' => (string) $snapshot->controlPanelOrderId,
            ];
        }
        if ($snapshot->shopOrderId > 0) {
            $rows[] = [
                'label' => self::LABEL_CP_SHOP_ORDER_ID,
                'value' => (string) $snapshot->shopOrderId,
            ];
        }
        if ($snapshot->months > 0) {
            $rows[] = ['label' => self::LABEL_MONTHS, 'value' => (string) $snapshot->months];
        }
        if ($snapshot->kopCode !== '') {
            $rows[] = ['label' => self::LABEL_KOP, 'value' => $snapshot->kopCode];
        }

        $rows[] = ['label' => self::LABEL_FIRST, 'value' => $this->money($snapshot->firstInstallment)];
        $rows[] = ['label' => self::LABEL_LOAN, 'value' => $this->money($snapshot->financedAmount)];
        $rows[] = ['label' => self::LABEL_MONTHLY, 'value' => $this->money($snapshot->monthlyInstallment)];
        $rows[] = ['label' => self::LABEL_TOTAL, 'value' => $this->money($snapshot->totalPayable)];
        $rows[] = [
            'label' => self::LABEL_GLP_GPR,
            'value' => $this->money($snapshot->glp) . '% / ' . $this->money($snapshot->gpr) . '%',
        ];

        if ($snapshot->process2) {
            if ($this->includesEgn($audience) && $sensitive !== null && $sensitive->egn !== '') {
                $rows[] = ['label' => self::LABEL_EGN, 'value' => $sensitive->egn];
            }
            if ($this->includesPhone2($audience) && $sensitive !== null && $sensitive->phone2 !== '') {
                $rows[] = ['label' => self::LABEL_PHONE2, 'value' => $sensitive->phone2];
            }
            if ($audience === FinancingPresentationAudience::CUSTOMER) {
                $rows[] = ['label' => self::LABEL_MESSAGE, 'value' => self::PROCESS2_MESSAGE];
            }
        } elseif (
            $audience === FinancingPresentationAudience::CUSTOMER
            && $status === BankStatus::LABEL_SEND_FAILED_SMARTUCF
        ) {
            $rows[] = ['label' => self::LABEL_MESSAGE, 'value' => self::SMARTUCF_TERMINAL_FAILURE_MESSAGE];
        }

        return $rows;
    }

    /**
     * @param list<array{label: string, value: string}> $rows
     */
    public function renderHtml(array $rows, string $title = self::TITLE): string
    {
        if ($rows === []) {
            return '';
        }
        $html = '<div class="mt-uni-credit-leasing-block">';
        if ($title !== '') {
            $html .= '<h3 class="mt-uni-credit-leasing-block__title">'
                . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</h3>';
        }
        $html .= '<table class="mt-uni-credit-leasing-block__table" style="border-collapse:collapse;width:100%;">';
        foreach ($rows as $row) {
            $html .= '<tr><th style="text-align:left;padding:4px 16px 4px 0;vertical-align:top;">'
                . htmlspecialchars($row['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</th><td style="padding:4px 0;">'
                . htmlspecialchars($row['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</td></tr>';
        }
        $html .= '</table></div>';

        return $html;
    }

    /**
     * Email-safe HTML for OC4 mail/order_alert (setHtml + &lt;br/&gt; line markup).
     * Newlines alone are collapsed by HTML mail clients.
     *
     * @param list<array{label: string, value: string}> $rows
     */
    public function renderBrHtml(array $rows, string $title = self::TITLE): string
    {
        if ($rows === []) {
            return '';
        }
        $parts = [];
        if ($title !== '') {
            $parts[] = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $parts[] = '';
        }
        foreach ($rows as $row) {
            $parts[] = htmlspecialchars($row['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . ': '
                . htmlspecialchars($row['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return implode('<br/>', $parts);
    }

    /**
     * Plain-text leasing block (Mail::setText / multipart text part).
     *
     * @param list<array{label: string, value: string}> $rows
     */
    public function renderText(array $rows, string $title = self::TITLE): string
    {
        if ($rows === []) {
            return '';
        }
        $lines = [];
        if ($title !== '') {
            $lines[] = $title;
            $lines[] = '';
        }
        foreach ($rows as $row) {
            $lines[] = $row['label'] . ': ' . $row['value'];
        }

        return implode("\n", $lines);
    }

    public function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    public function includesEgn(string $audience): bool
    {
        return $audience === FinancingPresentationAudience::ADMIN_EMAIL
            || $audience === FinancingPresentationAudience::ADMIN_PANEL;
    }

    public function includesPhone2(string $audience): bool
    {
        return $audience !== FinancingPresentationAudience::CUSTOMER;
    }
}
