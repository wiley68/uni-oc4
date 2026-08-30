<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Frozen leasing presentation values for one financing order (no live recalculation).
 *
 * @phpstan-type SnapshotArray array{
 *     shop_order_id: int,
 *     control_panel_order_id: int|null,
 *     process2: bool,
 *     months: int,
 *     kop_code: string,
 *     first_installment: float,
 *     financed_amount: float,
 *     monthly_installment: float,
 *     total_payable: float,
 *     glp: float,
 *     gpr: float
 * }
 */
final class FinancingPresentationSnapshot
{
    public function __construct(
        public readonly int $shopOrderId,
        public readonly ?int $controlPanelOrderId,
        public readonly bool $process2,
        public readonly int $months,
        public readonly string $kopCode,
        public readonly float $firstInstallment,
        public readonly float $financedAmount,
        public readonly float $monthlyInstallment,
        public readonly float $totalPayable,
        public readonly float $glp,
        public readonly float $gpr
    ) {
    }

    public static function fromSubmission(
        ValidatedFinancingSubmission $submission,
        int $shopOrderId,
        bool $process2,
        ?int $controlPanelOrderId = null
    ): self {
        $calc = $submission->financingCalculation;

        return new self(
            $shopOrderId,
            $controlPanelOrderId !== null && $controlPanelOrderId > 0 ? $controlPanelOrderId : null,
            $process2,
            (int) $calc->scheme->months,
            (string) $calc->scheme->kopCode,
            (float) $calc->firstInstallment->amount,
            (float) $calc->financedAmount,
            (float) $calc->monthlyInstallment,
            (float) $calc->totalPayable,
            (float) $calc->glp,
            (float) $calc->gpr
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $cp = isset($data['control_panel_order_id']) ? (int) $data['control_panel_order_id'] : 0;

        return new self(
            (int) ($data['shop_order_id'] ?? 0),
            $cp > 0 ? $cp : null,
            !empty($data['process2']),
            (int) ($data['months'] ?? 0),
            (string) ($data['kop_code'] ?? ''),
            (float) ($data['first_installment'] ?? 0),
            (float) ($data['financed_amount'] ?? 0),
            (float) ($data['monthly_installment'] ?? 0),
            (float) ($data['total_payable'] ?? 0),
            (float) ($data['glp'] ?? 0),
            (float) ($data['gpr'] ?? 0)
        );
    }

    /**
     * @return SnapshotArray
     */
    public function toArray(): array
    {
        return [
            'shop_order_id' => $this->shopOrderId,
            'control_panel_order_id' => $this->controlPanelOrderId,
            'process2' => $this->process2,
            'months' => $this->months,
            'kop_code' => $this->kopCode,
            'first_installment' => $this->firstInstallment,
            'financed_amount' => $this->financedAmount,
            'monthly_installment' => $this->monthlyInstallment,
            'total_payable' => $this->totalPayable,
            'glp' => $this->glp,
            'gpr' => $this->gpr,
        ];
    }

    public function withControlPanelOrderId(int $controlPanelOrderId): self
    {
        return new self(
            $this->shopOrderId,
            $controlPanelOrderId > 0 ? $controlPanelOrderId : null,
            $this->process2,
            $this->months,
            $this->kopCode,
            $this->firstInstallment,
            $this->financedAmount,
            $this->monthlyInstallment,
            $this->totalPayable,
            $this->glp,
            $this->gpr
        );
    }
}
