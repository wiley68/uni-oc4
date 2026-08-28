<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Wires CP auth, shop configuration, and admin health services for a store scope.
 *
 * @return array{
 *     credentials: ModuleCredentialsRepository,
 *     tokens: CpTokenRepository,
 *     client: ControlPanelClient,
 *     shopConfiguration: ShopConfigurationService,
 *     presenter: CpAdminHealthPresenter,
 *     credentialChange: CredentialChangeHandler
 * }
 */
final class CpServiceFactory
{
    /**
     * @return array{
     *     credentials: ModuleCredentialsRepository,
     *     tokens: CpTokenRepository,
     *     client: ControlPanelClient,
     *     shopConfiguration: ShopConfigurationService,
     *     presenter: CpAdminHealthPresenter,
     *     credentialChange: CredentialChangeHandler
     * }
     */
    public static function create(
        DbConnection $db,
        ModuleSettingStore $settings,
        int $storeId,
        string $catalogSslUrl,
        string $catalogPlainUrl,
        ?CpHttpTransport $transport = null,
        ?PersistenceClock $clock = null,
        ?callable $wallClock = null,
        ?string $cpSecretOverride = null
    ): array {
        $secretProvider = $cpSecretOverride !== null
            ? new FixedCpAuthSecretProvider($cpSecretOverride)
            : new CpAuthSecretProvider();
        $credentials = new ModuleCredentialsRepository($settings, $secretProvider);
        $secret = $secretProvider->getSecret() ?? '';
        if ($secret === '') {
            $secret = 'missing-cp-secret-placeholder';
        }
        $cipher = new ModuleSettingCipher($secret);
        $tokens = new CpTokenRepository($settings, $cipher, $storeId);
        $shopName = (new CanonicalShopUrlProvider())->resolve($catalogSslUrl, $catalogPlainUrl);
        $client = new ControlPanelClient(
            $credentials,
            $tokens,
            $transport ?? new CurlCpHttpTransport(),
            $shopName,
            $storeId,
            null,
            $wallClock
        );
        $cache = new ShopCacheRepository($db, $clock);
        $shopConfiguration = new ShopConfigurationService(
            $credentials,
            $cache,
            $client,
            $tokens,
            $storeId
        );
        $presenter = new CpAdminHealthPresenter($credentials, $tokens, $shopConfiguration, $storeId);
        $credentialChange = new CredentialChangeHandler($tokens, $cache, $storeId);

        return [
            'credentials'       => $credentials,
            'tokens'            => $tokens,
            'client'            => $client,
            'shopConfiguration' => $shopConfiguration,
            'presenter'         => $presenter,
            'credentialChange'  => $credentialChange,
        ];
    }
}
