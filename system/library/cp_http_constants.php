<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Central Control Panel HTTP transport defaults (Phase 4). */
final class CpHttpConstants
{
    public const CONNECT_TIMEOUT_SECONDS = 5;

    public const TOTAL_TIMEOUT_SECONDS = 15;

    /** Maximum response body size accepted from CP (1 MiB). */
    public const MAX_RESPONSE_BYTES = 1048576;

    public const REFRESH_MARGIN_SECONDS = 60;
}
