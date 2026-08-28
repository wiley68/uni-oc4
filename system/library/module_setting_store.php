<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Store-scoped OpenCart setting access for module secrets and CP tokens.
 */
interface ModuleSettingStore
{
    public function get(int $storeId, string $key): ?string;

    public function set(int $storeId, string $key, string $value): void;

    public function delete(int $storeId, string $key): void;
}
