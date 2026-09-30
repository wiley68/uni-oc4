<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class CurrencyDisplayLabel
{
    public function forAmount(string $iso): string
    {
        if (!(new CurrencyGate())->supports($iso)) {
            throw new \InvalidArgumentException('Financing currency must be EUR.');
        }

        return 'евро';
    }
}
