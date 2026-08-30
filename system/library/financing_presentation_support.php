<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Writes frozen leasing presentation at materialization / CP-complete boundaries.
 */
final class FinancingPresentationSupport
{
    public static function persistFromSubmission(
        DbConnection $db,
        int $attemptId,
        ValidatedFinancingSubmission $submission,
        int $localOrderId,
        array $shop,
        ?int $controlPanelOrderId = null
    ): void {
        $snapshot = FinancingPresentationSnapshot::fromSubmission(
            $submission,
            $localOrderId,
            ShopConfigurationFlags::isSecondaryProcess($shop),
            $controlPanelOrderId
        );
        (new FinancingPresentationRepository($db))->persist($attemptId, $snapshot);
    }

    public static function attachControlPanelOrderId(DbConnection $db, int $attemptId, int $cpOrderId): void
    {
        (new FinancingPresentationRepository($db))->attachControlPanelOrderId($attemptId, $cpOrderId);
    }
}
