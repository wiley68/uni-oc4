<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * In-memory Process 2 mailer for tests / when OC Mail is unavailable.
 */
final class RecordingProcessTwoMailer implements ProcessTwoMailPort
{
    /** @var list<array{audience: string, subject: string, body_html: string}> */
    public array $sent = [];

    public bool $forceFailure = false;

    public function __construct(private ProcessTwoLeasingMailPresenter $presenter = new ProcessTwoLeasingMailPresenter())
    {
    }

    public function sendProcess2Notifications(
        array $shop,
        array $orderContext,
        ?ProcessTwoSensitiveData $sensitive
    ): bool {
        if ($this->forceFailure) {
            throw new \RuntimeException('Forced Process 2 mail failure.');
        }

        $orderRef = (string) ($orderContext['order_id'] ?? '');
        $subject = 'УниКредит лизинг — ' . $orderRef;
        $adminEmails = $this->adminRecipients($shop, (string) ($orderContext['store_email'] ?? ''));
        $customerEmail = trim((string) ($orderContext['customer_email'] ?? ''));

        $allOk = true;
        if ($adminEmails !== []) {
            $adminHtml = $this->presenter->renderHtml($this->presenter->adminRows($orderContext, $sensitive));
            if (!str_contains($adminHtml, FinancingLeasingPresenter::TITLE)) {
                $allOk = false;
            } else {
                foreach ($adminEmails as $to) {
                    $this->sent[] = ['audience' => 'admin', 'to' => $to, 'subject' => $subject, 'body_html' => $adminHtml];
                }
            }
        }

        if ($customerEmail !== '' && !in_array(strtolower($customerEmail), array_map('strtolower', $adminEmails), true)) {
            $customerHtml = $this->presenter->renderHtml($this->presenter->customerRows($orderContext));
            if (stripos($customerHtml, 'ЕГН') !== false && preg_match('/\b\d{10}\b/', $customerHtml)) {
                // Safety: never allow EGN digits in customer body.
                $allOk = false;
            } elseif (!str_contains($customerHtml, FinancingLeasingPresenter::TITLE)) {
                $allOk = false;
            } else {
                $this->sent[] = [
                    'audience' => 'customer',
                    'to' => $customerEmail,
                    'subject' => $subject,
                    'body_html' => $customerHtml,
                ];
            }
        }

        // No recipients configured → treat as success (nothing to send).
        return $allOk;
    }

    /**
     * @param array<string, mixed> $shop
     * @return list<string>
     */
    private function adminRecipients(array $shop, string $storeEmail): array
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
        $storeEmail = trim($storeEmail);
        if ($storeEmail !== '' && filter_var($storeEmail, FILTER_VALIDATE_EMAIL)) {
            $emails = array_values(array_filter(
                $emails,
                static fn(string $email): bool => strtolower($email) !== strtolower($storeEmail)
            ));
        }

        return array_values(array_unique($emails));
    }
}
