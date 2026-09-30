<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class CurrencyGate
{
    public function supports(string $currencyIso): bool
    {
        return strtoupper(trim($currencyIso)) === 'EUR';
    }
}
