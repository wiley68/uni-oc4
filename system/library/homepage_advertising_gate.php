<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Homepage/module eligibility plus centrally controlled CP advertising flags.
 */
final class HomepageAdvertisingGate
{
    public function allowsPage(string $route): bool
    {
        return StorefrontRouteResolver::isHomepageRoute($route);
    }

    public function allowsLocalSettings(
        bool $moduleActive,
        bool $moduleEnabled,
        string $unicid
    ): bool {
        return $moduleActive
            && $moduleEnabled
            && trim($unicid) !== '';
    }

    /**
     * Cheap homepage check used before shop-cache lookup / asset enqueue.
     */
    public function allowsAssets(
        string $route,
        bool $moduleActive,
        bool $moduleEnabled,
        string $unicid
    ): bool {
        return $this->allowsPage($route)
            && $this->allowsLocalSettings($moduleActive, $moduleEnabled, $unicid);
    }

    /**
     * CP shop flags required to render the floating button (Woo uni_status + uni_container_status).
     *
     * @param array<string, mixed> $shop
     */
    public function allowsShop(array $shop): bool
    {
        return ShopConfigurationFlags::isYesFlag($shop['uni_status'] ?? 0)
            && ShopConfigurationFlags::isYesFlag($shop['uni_container_status'] ?? 0);
    }
}
