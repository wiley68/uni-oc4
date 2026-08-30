<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Builds Process 2 leasing email bodies via shared FinancingLeasingPresenter.
 */
final class ProcessTwoLeasingMailPresenter
{
    public const CUSTOMER_CONFIRMATION = FinancingLeasingPresenter::PROCESS2_MESSAGE;

    public function __construct(
        private FinancingLeasingPresenter $presenter = new FinancingLeasingPresenter()
    ) {
    }

    /**
     * @param array<string, mixed> $orderContext
     * @return list<array{label: string, value: string}>
     */
    public function adminRows(array $orderContext, ?ProcessTwoSensitiveData $sensitive): array
    {
        return $this->rows($orderContext, FinancingPresentationAudience::ADMIN_EMAIL, $sensitive);
    }

    /**
     * @param array<string, mixed> $orderContext
     * @return list<array{label: string, value: string}>
     */
    public function customerRows(array $orderContext): array
    {
        return $this->rows($orderContext, FinancingPresentationAudience::CUSTOMER, null);
    }

    /**
     * @param list<array{label: string, value: string}> $rows
     */
    public function renderHtml(array $rows): string
    {
        return $this->presenter->renderHtml($rows, FinancingLeasingPresenter::TITLE);
    }

    /**
     * @param list<array{label: string, value: string}> $rows
     */
    public function renderText(array $rows): string
    {
        return $this->presenter->renderText($rows, FinancingLeasingPresenter::TITLE);
    }

    /**
     * @param array<string, mixed> $orderContext
     * @return list<array{label: string, value: string}>
     */
    private function rows(
        array $orderContext,
        string $audience,
        ?ProcessTwoSensitiveData $sensitive
    ): array {
        $snapshotData = $orderContext['leasing_snapshot'] ?? null;
        if (is_array($snapshotData)) {
            $snapshot = FinancingPresentationSnapshot::fromArray($snapshotData);
        } else {
            $snapshot = new FinancingPresentationSnapshot(
                (int) ($orderContext['order_id'] ?? 0),
                isset($orderContext['control_panel_order_id']) ? (int) $orderContext['control_panel_order_id'] : null,
                true,
                (int) ($orderContext['months'] ?? 0),
                (string) ($orderContext['kop_code'] ?? ''),
                (float) ($orderContext['first_installment'] ?? 0),
                (float) ($orderContext['financed_amount'] ?? 0),
                (float) ($orderContext['monthly_amount'] ?? $orderContext['monthly_installment'] ?? 0),
                (float) ($orderContext['total_payable'] ?? 0),
                (float) ($orderContext['glp'] ?? 0),
                (float) ($orderContext['gpr'] ?? 0)
            );
        }
        $status = (string) ($orderContext['bank_status_label'] ?? BankStatus::LABEL_SENT_PROCESS2);

        return $this->presenter->rows($snapshot, $status, $audience, $sensitive);
    }
}
