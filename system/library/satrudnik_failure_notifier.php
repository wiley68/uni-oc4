<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Best-effort operational email to CP satrudnik_email on terminal bank failure.
 *
 * Uses OpenCart Mail + store SMTP settings (same contract as native order mail).
 * Does not persist status, navigate, call CP/SmartUCF, or alter native order mail.
 */
final class SatrudnikFailureNotifier
{
    /** @var list<string> */
    public const TARGET_STATUS_IDS = [
        BankStatus::SEND_FAILED_CP,
        BankStatus::SEND_FAILED_SMARTUCF,
    ];

    /**
     * @param (callable(string $to, string $subject, string $body, string $headers): bool)|null $mailer
     */
    public function __construct(
        private $mailer = null,
        private ?DbConnection $db = null
    ) {}

    /**
     * @param array<string, mixed> $shop hydrated local shop snapshot
     */
    public function notifyIfEligible(
        array $shop,
        int $orderId,
        string $statusId,
        string $statusLabel,
        ?string $previousStatusId,
        ?int $controlPanelOrderId = null,
        ?string $orderDateAdded = null
    ): bool {
        try {
            if ($orderId <= 0) {
                return false;
            }

            $statusId = trim($statusId);
            if (!in_array($statusId, self::TARGET_STATUS_IDS, true)) {
                return false;
            }

            $previous = $previousStatusId !== null ? trim($previousStatusId) : '';
            if ($previous !== '' && $previous === $statusId) {
                return false;
            }

            $email = trim((string) ($shop['satrudnik_email'] ?? ''));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return false;
            }

            $statusLabel = trim($statusLabel);
            if ($statusLabel === '') {
                return false;
            }

            $dateAdded = $orderDateAdded;
            if ($dateAdded === null || trim($dateAdded) === '') {
                $dateAdded = $this->resolveOrderDateAdded($orderId);
            }

            $subject = 'Проблем при изпращане на заявка за финансиране - поръчка ' . $orderId;
            $body = $this->composeBody($orderId, $statusId, $statusLabel, $controlPanelOrderId, $dateAdded);

            $sent = $this->dispatchMail($email, $subject, $body);
            if (!$sent) {
                error_log(
                    'mt_uni_credit: Satrudnik failure notification transport returned false'
                        . ' order_id=' . $orderId
                        . ' status_id=' . $statusId
                );
            }

            return $sent;
        } catch (\Throwable $exception) {
            error_log(
                'mt_uni_credit: Satrudnik failure notification failed'
                    . ' order_id=' . $orderId
                    . ' status_id=' . $statusId
                    . ' class=' . $exception::class
            );

            return false;
        }
    }

    private const OPENCART_MAIL_CLASS = 'Opencart\\System\\Library\\Mail';

    private function dispatchMail(string $to, string $subject, string $body): bool
    {
        $mailer = $this->mailer;
        if ($mailer !== null) {
            return (bool) $mailer($to, $subject, $body, '');
        }

        // Prefer OpenCart Mail + store SMTP settings (same contract as native order emails).
        if ($this->db !== null && class_exists(self::OPENCART_MAIL_CLASS)) {
            return $this->sendViaOpenCartMail($to, $subject, $body);
        }

        return false;
    }

    private function sendViaOpenCartMail(string $to, string $subject, string $body): bool
    {
        $settings = $this->loadStoreMailSettings();
        $engine = trim((string) ($settings['config_mail_engine'] ?? ''));
        if ($engine === '') {
            return false;
        }

        $from = trim((string) ($settings['config_email'] ?? ''));
        if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $sender = trim(html_entity_decode((string) ($settings['config_name'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($sender === '') {
            $sender = 'OpenCart';
        }

        $option = [
            'parameter'     => (string) ($settings['config_mail_parameter'] ?? ''),
            'smtp_hostname' => (string) ($settings['config_mail_smtp_hostname'] ?? ''),
            'smtp_username' => (string) ($settings['config_mail_smtp_username'] ?? ''),
            'smtp_password' => html_entity_decode(
                (string) ($settings['config_mail_smtp_password'] ?? ''),
                ENT_QUOTES,
                'UTF-8'
            ),
            'smtp_port'     => $settings['config_mail_smtp_port'] ?? '',
            'smtp_timeout'  => $settings['config_mail_smtp_timeout'] ?? '',
        ];

        $mailClass = self::OPENCART_MAIL_CLASS;
        $mail = new $mailClass($engine, $option);
        if (
            !is_object($mail)
            || !method_exists($mail, 'setTo')
            || !method_exists($mail, 'setFrom')
            || !method_exists($mail, 'setSender')
            || !method_exists($mail, 'setSubject')
            || !method_exists($mail, 'setText')
            || !method_exists($mail, 'send')
        ) {
            return false;
        }

        $mail->setTo($to);
        $mail->setFrom($from);
        $mail->setSender($sender);
        $mail->setSubject($subject);
        $mail->setText($body);

        return (bool) $mail->send();
    }

    /**
     * @return array<string, string>
     */
    private function loadStoreMailSettings(): array
    {
        if ($this->db === null) {
            return [];
        }

        $keys = [
            'config_mail_engine',
            'config_mail_parameter',
            'config_mail_smtp_hostname',
            'config_mail_smtp_username',
            'config_mail_smtp_password',
            'config_mail_smtp_port',
            'config_mail_smtp_timeout',
            'config_email',
            'config_name',
        ];
        $escaped = [];
        foreach ($keys as $key) {
            $escaped[] = "'" . $this->db->escape($key) . "'";
        }

        $table = $this->db->getPrefix() . 'setting';
        // Prefer store 0 (default) — matches native catalog mail when store settings are global.
        $result = $this->db->query(
            "SELECT `key`, `value`
             FROM `{$table}`
             WHERE `store_id` = 0
               AND `key` IN (" . implode(',', $escaped) . ')'
        );

        $out = [];
        if (is_object($result) && !empty($result->rows)) {
            foreach ($result->rows as $row) {
                $out[(string) $row['key']] = (string) $row['value'];
            }
        }

        return $out;
    }

    private function composeBody(
        int $orderId,
        string $statusId,
        string $statusLabel,
        ?int $controlPanelOrderId,
        ?string $orderDateAdded
    ): string {
        $lines = [
            'Проблем при изпращане на заявка за финансиране',
            '',
            'Магазин поръчка: ' . $orderId,
        ];

        $date = $orderDateAdded !== null ? trim($orderDateAdded) : '';
        if ($date !== '') {
            $lines[] = 'Дата на поръчката: ' . $date;
        }

        $cpId = $controlPanelOrderId !== null ? (int) $controlPanelOrderId : 0;
        if ($cpId > 0) {
            $lines[] = 'КП поръчка: ' . $cpId;
        }

        $lines[] = 'Статус: ' . $statusLabel . ' (' . $statusId . ')';

        return implode("\n", $lines);
    }

    private function resolveOrderDateAdded(int $orderId): ?string
    {
        if ($this->db === null || $orderId <= 0) {
            return null;
        }

        try {
            $table = $this->db->getPrefix() . 'order';
            $result = $this->db->query(
                "SELECT `date_added`
                 FROM `{$table}`
                 WHERE `order_id` = " . (int) $orderId . '
                 LIMIT 1'
            );
            if (!is_object($result) || $result->num_rows !== 1) {
                return null;
            }
            $date = trim((string) ($result->row['date_added'] ?? ''));

            return $date !== '' ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
