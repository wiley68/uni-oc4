<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Test double and lightweight runtime setting bag. */
final class InMemoryModuleSettingStore implements ModuleSettingStore
{
    /** @var array<int, array<string, string>> */
    private array $values = [];

    public function get(int $storeId, string $key): ?string
    {
        return $this->values[$storeId][$key] ?? null;
    }

    public function set(int $storeId, string $key, string $value): void
    {
        $this->values[$storeId][$key] = $value;
    }

    public function delete(int $storeId, string $key): void
    {
        unset($this->values[$storeId][$key]);
    }
}
