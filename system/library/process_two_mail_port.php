<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Process 2 leasing notifications (admin may receive EGN; customer never does).
 */
interface ProcessTwoMailPort
{
    /**
     * @param array<string, mixed> $shop
     * @param array<string, mixed> $orderContext Safe order fields (id, reference, customer name/email, amounts)
     * @return bool true when all required audience sends succeeded (or none configured)
     */
    public function sendProcess2Notifications(
        array $shop,
        array $orderContext,
        ?ProcessTwoSensitiveData $sensitive
    ): bool;
}
