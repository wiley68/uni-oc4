<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\ModuleLocalSettings;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceTableNames;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7 admin finalization contract — operator UI without temp panels.
 */
final class Phase7AdminFinalizationTest extends TestCase
{
    public function testAdminTwigHasNativeSaveOnlyAndRequiredSettings(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__) . '/admin/view/template/module/mt_uni_credit.twig');
        $bg = (string) file_get_contents(dirname(__DIR__) . '/admin/language/bg-bg/module/mt_uni_credit.php');

        self::assertStringContainsString('form="form-module"', $twig);
        self::assertStringContainsString('button_save', $twig);
        self::assertSame(1, substr_count($twig, 'form="form-module"'));
        self::assertStringNotContainsString('Запиши настройките', $twig);
        self::assertStringNotContainsString('Запиши настройките', $bg);

        self::assertStringContainsString('module_mt_uni_credit_status', $twig);
        self::assertStringContainsString(ModuleCredentialsRepository::UNICID_SETTING, $twig);
        self::assertStringContainsString(ModuleCredentialsRepository::SECRET_SETTING, $twig);
        self::assertStringContainsString(ModuleLocalSettings::ADVERTISING_ENABLED, $twig);
        self::assertStringContainsString(ModuleLocalSettings::DEBUG_ENABLED, $twig);
        self::assertStringContainsString(ModuleLocalSettings::PRODUCT_BUTTON_ACTION, $twig);
        self::assertStringContainsString(ModuleLocalSettings::BUTTON_TOP_SPACING, $twig);

        self::assertStringContainsString('button_refresh_bank_data', $twig);
        self::assertStringContainsString('button_download_journal', $twig);
        self::assertStringContainsString('form-refresh-bank', $twig);
        self::assertStringContainsString('disabled', $twig);

        self::assertStringNotContainsString('Банкови данни', $twig);
        self::assertStringNotContainsString('Диагностика', $twig);
        self::assertStringNotContainsString('health.', $twig);
        self::assertStringNotContainsString('deployment_ready', $twig);
        self::assertStringNotContainsString('cp_host', $twig);
        self::assertStringNotContainsString('auth_state', $twig);
    }

    public function testLanguageExposesDifferentiatedBankErrors(): void
    {
        $bg = (string) file_get_contents(dirname(__DIR__) . '/admin/language/bg-bg/module/mt_uni_credit.php');
        foreach ([
            'Липсва UNICID.',
            'Липсва Secret.',
            'Неуспешно удостоверяване към Control Panel.',
            'Control Panel временно не отговаря.',
            'Получени са невалидни банкови данни.',
            'Банковите данни не могат да бъдат обновени поради техническа грешка.',
        ] as $message) {
            self::assertStringContainsString($message, $bg);
        }
    }

    public function testModuleLocalSettingsMatchPs9Semantics(): void
    {
        self::assertSame(0, ModuleLocalSettings::DEFAULT_ADVERTISING_ENABLED);
        self::assertSame(0, ModuleLocalSettings::DEFAULT_DEBUG_ENABLED);
        self::assertSame('add_to_cart', ModuleLocalSettings::DEFAULT_PRODUCT_BUTTON_ACTION);
        self::assertSame(0, ModuleLocalSettings::DEFAULT_BUTTON_TOP_SPACING);
        self::assertSame(200, ModuleLocalSettings::MAX_BUTTON_TOP_SPACING);
        self::assertSame('buy', ModuleLocalSettings::normalizeProductButtonAction('buy'));
        self::assertSame('add_to_cart', ModuleLocalSettings::normalizeProductButtonAction('nope'));
        self::assertSame(200, ModuleLocalSettings::normalizeButtonTopSpacing(999));
        self::assertSame(0, ModuleLocalSettings::normalizeButtonTopSpacing(-5));
    }

    public function testProductionPersistenceIsExactlySevenTables(): void
    {
        $tables = PersistenceTableNames::allPersistenceTables();
        self::assertCount(7, $tables);
        self::assertSame([
            'mt_uni_credit_shop_cache',
            'mt_uni_credit_api_nonce',
            'mt_uni_credit_operation_lock',
            'mt_uni_credit_financing_attempt',
            'mt_uni_credit_order_correlation',
            'mt_uni_credit_order_bank_status',
            'mt_uni_credit_diagnostic_debug_log',
        ], $tables);
    }

    public function testModelResolvesCatalogUrlFallbackAndDoesNotGateOnSmartUcf(): void
    {
        $model = (string) file_get_contents(dirname(__DIR__) . '/admin/model/module/mt_uni_credit.php');
        self::assertStringContainsString('resolveCatalogUrls', $model);
        self::assertStringContainsString('\\HTTPS_CATALOG', $model);
        self::assertStringContainsString('\\HTTP_CATALOG', $model);
        self::assertStringContainsString('shop_url_missing', $model);
        self::assertStringContainsString('authentication_failed', $model);
        self::assertStringContainsString('transient_failure', $model);
        self::assertStringContainsString('shop_snapshot_invalid', $model);
        self::assertStringNotContainsString('DeploymentHealthService()->isDeploymentReady', $model);
        self::assertStringNotContainsString('smartucf', strtolower($model));
        self::assertStringNotContainsString('avalon_cert.pem', $model);
        self::assertStringNotContainsString('avalon_private_key.pem', $model);
    }

    public function testSavePreservesExistingSecretAndTokens(): void
    {
        $model = (string) file_get_contents(dirname(__DIR__) . '/admin/model/module/mt_uni_credit.php');
        self::assertStringContainsString('editSetting() deletes all rows', $model);
        self::assertStringContainsString('getSetting(ModuleConstants::MODULE_SETTING_CODE, $storeId)', $model);
        self::assertStringContainsString('editSetting(ModuleConstants::MODULE_SETTING_CODE, $payload, $storeId)', $model);
    }
}
