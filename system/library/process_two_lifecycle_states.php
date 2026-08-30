<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class ProcessTwoLifecycleStates
{
    public const NOT_STARTED = 'not_started';
    public const PREPARING = 'process2_preparing';
    public const PREPARED = 'process2_prepared';
    public const FAILED = 'process2_failed';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::NOT_STARTED,
            self::PREPARING,
            self::PREPARED,
            self::FAILED,
        ];
    }
}
