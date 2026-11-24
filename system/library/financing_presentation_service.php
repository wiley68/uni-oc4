<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Facade: resolve leasing presentation HTML/rows for an OpenCart order.
 */
final class FinancingPresentationService
{
    public function __construct(
        private FinancingPresentationRepository $repository,
        private FinancingLeasingPresenter $presenter = new FinancingLeasingPresenter()
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
        return $this->presenter->rows($snapshot, $status, $audience);
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
}
