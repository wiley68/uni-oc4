<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Facade: resolve leasing presentation HTML/rows for an OpenCart order.
 */
final class FinancingPresentationService
{
    private bool $cipherResolved = false;

    public function __construct(
        private FinancingPresentationRepository $repository,
        private FinancingLeasingPresenter $presenter = new FinancingLeasingPresenter(),
        private ?ProcessTwoSensitiveCipher $cipher = null
    ) {
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public function rowsForOrder(int $storeId, int $orderId, string $audience): array
    {
        $snapshot = $this->repository->findByOrderId($storeId, $orderId);
        if ($snapshot === null) {
            return [];
        }
        $status = $this->repository->findBankStatusLabel($storeId, $orderId);
        $sensitive = null;
        if ($this->presenter->includesEgn($audience) || $this->presenter->includesPhone2($audience)) {
            $sensitive = $this->decryptSensitive($storeId, $orderId);
        }

        return $this->presenter->rows($snapshot, $status, $audience, $sensitive);
    }

    public function htmlForOrder(
        int $storeId,
        int $orderId,
        string $audience,
        ?string $title = null
    ): string {
        $rows = $this->rowsForOrder($storeId, $orderId, $audience);
        if ($rows === []) {
            return '';
        }
        $resolvedTitle = $title ?? (
            $audience === FinancingPresentationAudience::ADMIN_PANEL
                ? FinancingLeasingPresenter::ADMIN_TITLE
                : FinancingLeasingPresenter::TITLE
        );

        return $this->presenter->renderHtml($rows, $resolvedTitle);
    }

    public function isUniCreditOrder(int $storeId, int $orderId): bool
    {
        return $this->repository->findByOrderId($storeId, $orderId) !== null
            || $this->repository->findAttemptRowByOrderId($storeId, $orderId) !== null;
    }

    private function decryptSensitive(int $storeId, int $orderId): ?ProcessTwoSensitiveData
    {
        $row = $this->repository->findAttemptRowByOrderId($storeId, $orderId);
        if ($row === null) {
            return null;
        }
        $enc = (string) ($row['process2_sensitive_enc'] ?? '');
        if ($enc === '') {
            return null;
        }
        $cipher = $this->resolveCipher();
        if ($cipher === null) {
            return null;
        }
        try {
            return $cipher->decrypt($enc);
        } catch (\Throwable) {
            error_log('mt_uni_credit: leasing presentation sensitive decrypt failed order_id=' . $orderId);

            return null;
        }
    }

    private function resolveCipher(): ?ProcessTwoSensitiveCipher
    {
        if ($this->cipher !== null || $this->cipherResolved) {
            return $this->cipher;
        }
        $this->cipherResolved = true;
        try {
            $this->cipher = new ProcessTwoSensitiveCipher();
        } catch (\Throwable) {
            $this->cipher = null;
        }

        return $this->cipher;
    }
}
