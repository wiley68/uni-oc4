<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class SmartUcfLifecycleStates
{
    public const NOT_STARTED = 'not_started';
    public const SUBMITTING = 'submitting';
    public const CREATED = 'created';
    public const FAILED = 'failed';
    public const OUTCOME_UNKNOWN = 'outcome_unknown';
}
