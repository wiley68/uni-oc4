<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class FirstInstallmentState
{
    public float $amount;

    public bool $locked;

    public bool $visible;

    public function __construct(float $amount, bool $locked, bool $visible)
    {
        $this->amount = $amount;
        $this->locked = $locked;
        $this->visible = $visible;
    }
}
