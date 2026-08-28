<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** Invalidates CP auth and scoped shop cache when local credentials change. */
final class CredentialChangeHandler
{
    private CpTokenRepository $tokens;

    private ShopCacheRepository $cache;

    private int $storeId;

    public function __construct(CpTokenRepository $tokens, ShopCacheRepository $cache, int $storeId)
    {
        $this->tokens = $tokens;
        $this->cache = $cache;
        $this->storeId = $storeId;
    }

    public function onCredentialsChanged(string $previousUnicid, string $newUnicid): void
    {
        $this->tokens->invalidate();

        $previous = trim($previousUnicid);
        if ($previous !== '') {
            $this->cache->deleteScoped($this->storeId, $previous);
        }

        $new = trim($newUnicid);
        if ($new !== '' && $new !== $previous) {
            $this->cache->deleteScoped($this->storeId, $new);
        }
    }

    public function onSecretDeploymentChanged(): void
    {
        $this->tokens->invalidate();
    }
}
