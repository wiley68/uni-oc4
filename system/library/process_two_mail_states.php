<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Process 2 leasing-mail durability states (authority over legacy process2_mail_sent).
 */
final class ProcessTwoMailStates
{
    public const NOT_SENT = 'not_sent';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    /** Seconds after which a stuck `sending` claim may be reclaimed. */
    public const SENDING_STALE_SECONDS = 300;
}
