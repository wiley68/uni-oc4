<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Shop configuration cache orchestration — lookup, refresh, validation, invalidation.
 */
class ShopConfigurationService
{
    private ModuleCredentialsRepository $credentials;

    private ShopCacheRepository $cache;

    private ControlPanelClient $client;

    private CpTokenRepository $tokens;

    private ShopConfigurationSnapshotValidator $snapshotValidator;

    private SmartUcfCredentialRepository $smartUcfCredentials;

    private SmartUcfCredentialPersistence $credentialPersistence;

    private int $storeId;

    public function __construct(
        ModuleCredentialsRepository $credentials,
        ShopCacheRepository $cache,
        ControlPanelClient $client,
        CpTokenRepository $tokens,
        int $storeId,
        SmartUcfCredentialRepository $smartUcfCredentials,
        SmartUcfCredentialPersistence $credentialPersistence,
        ?ShopConfigurationSnapshotValidator $snapshotValidator = null
    ) {
        $this->credentials = $credentials;
        $this->cache = $cache;
        $this->client = $client;
        $this->tokens = $tokens;
        $this->storeId = $storeId;
        $this->smartUcfCredentials = $smartUcfCredentials;
        $this->credentialPersistence = $credentialPersistence;
        $this->snapshotValidator = $snapshotValidator ?? new ShopConfigurationSnapshotValidator();
    }

    /** @return array<string, mixed> */
    public function get(bool $forceRefresh = false): array
    {
        $unicid = $this->credentials->getUnicid($this->storeId);
        if ($unicid === '') {
            $this->purgePermanentFailure($unicid);
            throw new CpAuthenticationException('UNICID is required to load the shop configuration.');
        }

        if (!$forceRefresh) {
            $cached = $this->cache->findFresh($this->storeId, $unicid);
            if ($cached !== null) {
                return $this->hydrateRuntime($cached['shop_data']);
            }
        }

        return $this->hydrateRuntime($this->refresh($unicid));
    }

    /**
     * Cache-only snapshot — never calls remote CP (Phase 4 foundation for Phase 5 FO).
     *
     * @return array<string, mixed>|null
     */
    public function getCachedOnly(): ?array
    {
        $unicid = $this->credentials->getUnicid($this->storeId);
        if ($unicid === '') {
            return null;
        }

        try {
            $cached = $this->cache->findFresh($this->storeId, $unicid);

            return $cached !== null ? $this->hydrateRuntime($cached['shop_data']) : null;
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        $unicid = $this->credentials->getUnicid($this->storeId);
        if ($unicid === '') {
            return null;
        }

        return $this->cache->findMetadata($this->storeId, $unicid);
    }

    /** @return array<string, mixed> */
    public function refreshRemote(): array
    {
        $unicid = $this->credentials->getUnicid($this->storeId);
        if ($unicid === '') {
            throw new CpAuthenticationException('UNICID is required to refresh the shop configuration.');
        }

        return $this->hydrateRuntime($this->refresh($unicid));
    }

    /**
     * CP push path: validate and replace local shop cache (no remote GET).
     *
     * @param array<string, mixed> $shopData
     */
    public function replaceSnapshot(string $unicid, array $shopData): bool
    {
        $unicid = trim($unicid);
        if ($unicid === '' || $shopData === []) {
            return false;
        }

        $this->snapshotValidator->validate($shopData, $unicid);
        $this->credentialPersistence->persistValidatedSnapshot($this->storeId, $unicid, $shopData);

        return true;
    }

    public function smartUcfCredentials(): SmartUcfCredentialRepository
    {
        return $this->smartUcfCredentials;
    }

    /** @return array<string, mixed> */
    private function refresh(string $unicid): array
    {
        try {
            $response = $this->client->getShop();
            $shopData = $response['data'] ?? null;
            if (!is_array($shopData) || $shopData === []) {
                throw new CpInvalidPayloadException('The Control Panel returned no usable shop configuration.');
            }

            $this->snapshotValidator->validate($shopData, $unicid);

            return $this->credentialPersistence->persistValidatedSnapshot($this->storeId, $unicid, $shopData);
        } catch (ShopSnapshotValidationException $exception) {
            throw $exception;
        } catch (CpAuthenticationException $exception) {
            $this->purgePermanentFailure($unicid);
            throw $exception;
        } catch (CpHttpException $exception) {
            if ($exception->isPermanentAuthOrConfiguration()) {
                $this->purgePermanentFailure($unicid);
            }

            throw $exception;
        } catch (CpInvalidPayloadException $exception) {
            $this->purgePermanentFailure($unicid);
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $shopData
     * @return array<string, mixed>
     */
    private function hydrateRuntime(array $shopData): array
    {
        return $this->smartUcfCredentials->hydrateShopSnapshot($this->storeId, $shopData);
    }

    private function purgePermanentFailure(string $unicid): void
    {
        if ($unicid !== '') {
            $this->cache->deleteScoped($this->storeId, $unicid);
        }
        $this->tokens->invalidate();
    }
}
