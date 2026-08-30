<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Accepted inbound bank / CP status_id vocabulary for order-bank-status.
 */
final class InboundBankStatusVocabulary
{
    /** @var list<string> */
    public const ALLOWED_STATUS_IDS = [
        'cp_sent',
        'smartucf_sent',
        'bank_sent_process1',
        'bank_sent_process2',
        'bank_send_failed',
        'bank_send_failed_cp',
        'bank_send_failed_smartucf',
    ];

    public static function isAccepted(string $statusId): bool
    {
        $statusId = strtolower(trim($statusId));
        if ($statusId === '') {
            return false;
        }

        if (in_array($statusId, self::ALLOWED_STATUS_IDS, true)) {
            return true;
        }

        // SmartUCF / bank numeric codes pushed by CP status sync (e.g. "10", "08").
        return (bool) preg_match('/^\d{1,3}$/', $statusId);
    }
}
