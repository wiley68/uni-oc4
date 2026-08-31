<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Resolves homepage advertising view-model once per request (cache-only CP shop).
 */
final class HomepageAdvertisingContextResolver
{
    /** @var array<string, array<string, mixed>|null|false> */
    private static array $requestCache = [];

    public function __construct(
        private HomepageAdvertisingGate $gate,
        private HomepageAdvertisingPresenter $presenter,
        private ShopConfigurationService $shopConfiguration,
        private ModuleCredentialsRepository $credentials,
        private int $storeId
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(
        bool $isHomepage,
        bool $moduleEnabled,
        bool $advertisingEnabled,
        bool $isMobile,
        string $defaultLogoUrl
    ): ?array {
        $cacheKey = implode('|', [
            (string) $this->storeId,
            $isHomepage ? '1' : '0',
            $moduleEnabled ? '1' : '0',
            $advertisingEnabled ? '1' : '0',
            $isMobile ? '1' : '0',
        ]);
        if (array_key_exists($cacheKey, self::$requestCache)) {
            $cached = self::$requestCache[$cacheKey];

            return is_array($cached) ? $cached : null;
        }

        if (
            !$isHomepage
            || !$this->gate->allowsLocalSettings(
                $moduleEnabled,
                $moduleEnabled,
                $advertisingEnabled,
                $this->credentials->getUnicid($this->storeId)
            )
        ) {
            self::$requestCache[$cacheKey] = false;

            return null;
        }

        $shop = $this->shopConfiguration->getCachedOnly();
        if ($shop === null || $shop === []) {
            self::$requestCache[$cacheKey] = false;

            return null;
        }

        $context = $this->presenter->present($shop, $isMobile, $defaultLogoUrl);
        self::$requestCache[$cacheKey] = $context ?? false;

        return $context;
    }

    public static function resetRequestCache(): void
    {
        self::$requestCache = [];
    }
}
