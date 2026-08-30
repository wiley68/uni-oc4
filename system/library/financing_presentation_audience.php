<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Explicit presentation audiences (Woo/PS9 parity).
 */
final class FinancingPresentationAudience
{
    /** Thank You, standard OC customer email, Process 2 customer leasing mail — never EGN/phone2. */
    public const CUSTOMER = 'customer';

    /** Process 2 merchant/admin leasing mail — EGN + phone2 allowed. */
    public const ADMIN_EMAIL = 'admin_email';

    /** Admin Order detail panel — PS9 parity: EGN + phone2 for Process 2. */
    public const ADMIN_PANEL = 'admin_panel';
}
