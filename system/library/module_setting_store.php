<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Store-scoped OpenCart setting access for module secrets and CP tokens.
 */
interface ModuleSettingStore
{
    public function get(int $storeId, string $key): ?string;

    /**
     * Read multiple keys for one store in a single consistent lookup where supported.
     *
     * @param list<string> $keys
     * @return array<string, ?string> map of key => value|null
     */
    public function getMany(int $storeId, array $keys): array;

    public function set(int $storeId, string $key, string $value): void;

    public function delete(int $storeId, string $key): void;
}
