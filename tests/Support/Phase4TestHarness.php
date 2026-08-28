<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use Opencart\System\Library\Extension\MtUniCredit\CpServiceFactory;
use Opencart\System\Library\Extension\MtUniCredit\DbConnection;
use Opencart\System\Library\Extension\MtUniCredit\InMemoryModuleSettingStore;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleEncryptionKeyProvider;
use Opencart\System\Library\Extension\MtUniCredit\ModuleSettingCipher;
use Opencart\System\Library\Extension\MtUniCredit\ModuleSettingStore;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceClock;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller;

require_once dirname(__DIR__) . '/fixtures/cp_shop_snapshot.php';

final class Phase4TestHarness
{
    public const TEST_SECRET = 'phase4-test-cp-secret-value';

    public const TEST_UNICID = '123e4567-e89b-12d3-a456-426614174000';

    public const TEST_STORE_ID = 900001;

    public const TEST_SHOP_URL = 'https://shop.example.com';

    public static function settings(): InMemoryModuleSettingStore
    {
        return new InMemoryModuleSettingStore();
    }

    public static function derivedTestKey(): string
    {
        return (new ModuleEncryptionKeyProvider())->resolveDerivedKey(ModuleEncryptionKeyProvider::testSecretInput());
    }

    public static function cipher(): ModuleSettingCipher
    {
        return new ModuleSettingCipher(self::derivedTestKey());
    }

    public static function prepareCredentials(ModuleSettingStore $settings, int $storeId = self::TEST_STORE_ID): void
    {
        $settings->set($storeId, ModuleCredentialsRepository::UNICID_SETTING, self::TEST_UNICID);
        $settings->set(
            $storeId,
            ModuleCredentialsRepository::SECRET_SETTING,
            self::cipher()->encrypt(self::TEST_SECRET)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function services(
        FakeCpHttpTransport $transport,
        ?ModuleSettingStore $settings = null,
        ?DbConnection $db = null,
        int $storeId = self::TEST_STORE_ID,
        int $now = 1_700_000_000
    ): array {
        $settings ??= self::settings();
        self::prepareCredentials($settings, $storeId);

        if ($db === null && PersistenceIntegrationHarness::enabled()) {
            PersistenceIntegrationHarness::resetTables();
            $db = PersistenceIntegrationHarness::connection();
        }

        if ($db === null) {
            throw new \RuntimeException('Database connection required for this harness setup.');
        }

        (new PersistenceSchemaInstaller($db))->installAll();

        return CpServiceFactory::create(
            $db,
            $settings,
            $storeId,
            self::TEST_SHOP_URL,
            self::TEST_SHOP_URL,
            $transport,
            new PersistenceClock(static fn(): int => $now),
            static fn(): int => $now,
            ModuleEncryptionKeyProvider::testSecretInput()
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function loginSuccessPayload(array $overrides = []): array
    {
        return array_merge([
            'success' => true,
            'access_token' => str_repeat('a', 64),
            'token_type' => 'Bearer',
            'expires_in' => 86400,
            'shop' => [
                'id' => 1,
                'name' => self::TEST_SHOP_URL,
                'unicid' => self::TEST_UNICID,
            ],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function shopSuccessPayload(?array $snapshot = null): array
    {
        $data = $snapshot ?? mt_uni_credit_valid_shop_snapshot();

        return [
            'success' => true,
            'message' => 'ok',
            'data' => $data,
        ];
    }
}
