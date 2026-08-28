<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * OpenCart store scope contract.
 *
 * OpenCart 4.x uses store_id = 0 for the default store and positive ids for
 * additional stores. Negative ids are invalid. Explicit 0 is not "missing".
 */
final class OpenCartStoreScope
{
    public static function isValid(int $storeId): bool
    {
        return $storeId >= 0;
    }

    /**
     * @throws PersistenceValidationException when store id is negative
     */
    public static function require(int $storeId): void
    {
        if ($storeId < 0) {
            throw new PersistenceValidationException('Store scope is required.');
        }
    }
}
