<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * More than one financing attempt matches the authorized store + order_id.
 */
final class FinancingOrderAmbiguousException extends \RuntimeException
{
}
