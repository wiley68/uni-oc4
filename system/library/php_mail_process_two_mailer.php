<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Process 2 mail via PHP mail() — no EGN in customer audience.
 */
final class PhpMailProcessTwoMailer implements ProcessTwoMailPort
{
    public function __construct(
        private ProcessTwoLeasingMailPresenter $presenter = new ProcessTwoLeasingMailPresenter(),
        private ?RecordingProcessTwoMailer $recorder = null
    ) {
    }

    public function sendProcess2Notifications(
        array $shop,
        array $orderContext,
        ?ProcessTwoSensitiveData $sensitive
    ): bool {
        if ($this->recorder !== null) {
            return $this->recorder->sendProcess2Notifications($shop, $orderContext, $sensitive);
        }

        $orderRef = (string) ($orderContext['order_id'] ?? '');
        $subject = 'УниКредит лизинг — ' . $orderRef;
        $from = trim((string) ($orderContext['store_email'] ?? ''));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $from = 'noreply@localhost';
        }

        $adminEmails = $this->parseAdminEmails($shop, $from);
        $customerEmail = trim((string) ($orderContext['customer_email'] ?? ''));
        $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: {$from}\r\n";

        $ok = true;
        if ($adminEmails !== []) {
            $adminHtml = $this->presenter->renderHtml($this->presenter->adminRows($orderContext, $sensitive));
            foreach ($adminEmails as $to) {
                if (!@mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $adminHtml, $headers)) {
                    $ok = false;
                }
            }
        }

        if ($customerEmail !== ''
            && !in_array(strtolower($customerEmail), array_map('strtolower', $adminEmails), true)
        ) {
            $customerHtml = $this->presenter->renderHtml($this->presenter->customerRows($orderContext));
            // Hard privacy guard: never send EGN digits to customer.
            if (preg_match('/\b\d{10}\b/', $customerHtml) && str_contains($customerHtml, 'ЕГН')) {
                error_log('mt_uni_credit: blocked customer Process 2 mail containing EGN');
                $ok = false;
            } elseif (!@mail($customerEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $customerHtml, $headers)) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * @param array<string, mixed> $shop
     * @return list<string>
     */
    private function parseAdminEmails(array $shop, string $storeEmail): array
    {
        $raw = (string) ($shop['uni_email'] ?? '');
        $parts = preg_split('/[,;]+/', $raw) ?: [];
        $emails = [];
        foreach ($parts as $part) {
            $email = trim($part);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }
        if ($storeEmail !== '') {
            $emails = array_values(array_filter(
                $emails,
                static fn(string $email): bool => strtolower($email) !== strtolower($storeEmail)
            ));
        }

        return array_values(array_unique($emails));
    }
}
