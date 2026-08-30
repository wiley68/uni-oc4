<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class BankStatus
{
    public const SENT_PROCESS1 = 'bank_sent_process1';
    public const SENT_PROCESS2 = 'bank_sent_process2';
    public const SEND_FAILED_SMARTUCF = 'bank_send_failed_smartucf';

    public const LABEL_SENT_PROCESS1 = 'Изпратен Банка - Процес 1';
    public const LABEL_SENT_PROCESS2 = 'Изпратен Банка - Процес 2';
    public const LABEL_SEND_FAILED_SMARTUCF = 'Неуспешно изпратен Банка - SmartUCF';

    /** @return array{status_id: string, status_label: string} */
    public static function process1Sent(): array
    {
        return [
            'status_id' => self::SENT_PROCESS1,
            'status_label' => self::LABEL_SENT_PROCESS1,
        ];
    }

    /** @return array{status_id: string, status_label: string} */
    public static function smartUcfFailure(): array
    {
        return [
            'status_id' => self::SEND_FAILED_SMARTUCF,
            'status_label' => self::LABEL_SEND_FAILED_SMARTUCF,
        ];
    }
}
