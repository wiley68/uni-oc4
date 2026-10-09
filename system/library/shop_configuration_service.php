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

    private ?DbConnection $db;

    private PersistenceClock $clock;

    public function __construct(
        ModuleCredentialsRepository $credentials,
        ShopCacheRepository $cache,
        ControlPanelClient $client,
        CpTokenRepository $tokens,
        int $storeId,
        SmartUcfCredentialRepository $smartUcfCredentials,
        SmartUcfCredentialPersistence $credentialPersistence,
        ?ShopConfigurationSnapshotValidator $snapshotValidator = null,
        ?DbConnection $db = null,
        ?PersistenceClock $clock = null
    ) {
        $this->credentials = $credentials;
        $this->cache = $cache;
        $this->client = $client;
        $this->tokens = $tokens;
        $this->storeId = $storeId;
        $this->smartUcfCredentials = $smartUcfCredentials;
        $this->credentialPersistence = $credentialPersistence;
        $this->snapshotValidator = $snapshotValidator ?? new ShopConfigurationSnapshotValidator();
        $this->db = $db;
        $this->clock = $clock ?? new PersistenceClock();
    }

    /** @return array<string, mixed> */
    public function get(bool $forceRefresh = false): array
    {
        return $forceRefresh ? $this->getForSubmission() : $this->getForPresentation();
    }

    /** @return array<string, mixed> */
    public function getForPresentation(): array
    {
        return $this->resolve(false);
    }

    /** @return array<string, mixed> */
    public function getForSubmission(): array
    {
        return $this->resolve(true);
    }

    /** @return array<string, mixed> */
    private function resolve(bool $submission): array
    {
        $unicid = $this->credentials->getUnicid($this->storeId);
        if ($unicid === '') {
            $this->purgePermanentFailure($unicid);
            throw new CpAuthenticationException('UNICID is required to load the shop configuration.');
        }

        $fresh = $this->cache->findFresh($this->storeId, $unicid);
        if ($fresh !== null) {
            return $this->hydrateRuntime($fresh['shop_data']);
        }

        $latest = $this->validatedLatest($unicid);
        $lkgEligible = !$submission && $this->isLkgEligible($latest);
        $lockName = 'mtuc_shop_' . substr(hash('sha256', $this->storeId . '|' . $unicid), 0, 40);

        if (!$this->acquireRefreshLock($lockName, 0)) {
            if ($lkgEligible) {
                return $this->hydrateRuntime($latest['shop_data']);
            }
            if ($this->acquireRefreshLock($lockName, SecurityConstants::SHOP_REFRESH_LOCK_WAIT_SECONDS)) {
                try {
                    $fresh = $this->cache->findFresh($this->storeId, $unicid);
                    if ($fresh !== null) {
                        return $this->hydrateRuntime($fresh['shop_data']);
                    }
                } finally {
                    $this->releaseRefreshLock($lockName);
                }
            }
            throw new CpConnectionException('Shop configuration refresh is already in progress.');
        }

        try {
            $fresh = $this->cache->findFresh($this->storeId, $unicid);
            if ($fresh !== null) {
                return $this->hydrateRuntime($fresh['shop_data']);
            }
            try {
                return $this->hydrateRuntime($this->refresh($unicid));
            } catch (CpException $exception) {
                if ($lkgEligible && $exception->isTransient()) {
                    return $this->hydrateRuntime($latest['shop_data']);
                }
                throw $exception;
            }
        } finally {
            $this->releaseRefreshLock($lockName);
        }
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

            return $this->credentialPersistence->persistValidatedSnapshot($this->storeId, $unicid, $shopData, $this->client->origin());
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
        }
    }

    /** @return array{shop_data: array<string, mixed>, fetched_at: string, expires_at: string}|null */
    private function validatedLatest(string $unicid): ?array
    {
        $latest = $this->cache->findLatest($this->storeId, $unicid);
        if ($latest === null) {
            return null;
        }
        try {
            $this->snapshotValidator->validate($latest['shop_data'], $unicid);
        } catch (\Throwable) {
            return null;
        }

        return $latest;
    }

    /** @param array{expires_at: string}|null $latest */
    private function isLkgEligible(?array $latest): bool
    {
        if ($latest === null) {
            return false;
        }
        $expiresAt = strtotime($latest['expires_at'] . ' UTC');

        return $expiresAt !== false
            && $expiresAt <= $this->clock->now()
            && $this->clock->now() <= $expiresAt + SecurityConstants::SHOP_CACHE_LKG_SECONDS;
    }

    private function acquireRefreshLock(string $name, int $waitSeconds): bool
    {
        if ($this->db === null) {
            return true;
        }
        $result = $this->db->query("SELECT GET_LOCK('" . $this->db->escape($name) . "', " . $waitSeconds . ") AS `acquired`");

        return is_object($result) && (int) ($result->row['acquired'] ?? 0) === 1;
    }

    private function releaseRefreshLock(string $name): void
    {
        if ($this->db !== null) {
            $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($name) . "')");
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
