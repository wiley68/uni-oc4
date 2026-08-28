<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptState;
use Opencart\System\Library\Extension\MtUniCredit\OperationEntryPoint;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceTableNames;
use Opencart\System\Library\Extension\MtUniCredit\SecurityConstants;
use PHPUnit\Framework\TestCase;

final class Phase3SchemaContractTest extends TestCase
{
    public function testPhase3TablesAreExactlyFour(): void
    {
        self::assertSame([
            'mt_uni_credit_shop_cache',
            'mt_uni_credit_api_nonce',
            'mt_uni_credit_operation_lock',
            'mt_uni_credit_financing_attempt',
        ], PersistenceTableNames::phase3Tables());
    }

    public function testCreateStatementsDefineExpectedKeysAndColumns(): void
    {
        $sql = implode("\n", PersistenceSchemaInstaller::createTableStatements('oc_'));

        self::assertStringContainsString(PersistenceTableNames::SHOP_CACHE, $sql);
        self::assertStringContainsString('UNIQUE KEY `uniq_mt_uni_credit_shop_cache_store_unicid` (`store_id`, `unicid`)', $sql);
        self::assertStringContainsString('KEY `idx_mt_uni_credit_shop_cache_expires` (`expires_at`)', $sql);

        self::assertStringContainsString('UNIQUE KEY `uniq_mt_uni_credit_api_nonce` (`store_id`, `unicid`, `nonce_hash`)', $sql);
        self::assertStringContainsString('`nonce_hash` CHAR(64) NOT NULL', $sql);

        self::assertStringContainsString('UNIQUE KEY `uniq_mt_uni_credit_operation_lock` (`store_id`, `entry_point`, `operation_key_hash`)', $sql);
        self::assertStringContainsString('`owner_token` CHAR(32) NOT NULL', $sql);

        self::assertStringContainsString('UNIQUE KEY `uniq_mt_uni_credit_submission_token` (`submission_token`)', $sql);
        self::assertStringContainsString('UNIQUE KEY `uniq_mt_uni_credit_store_order` (`store_id`, `order_id`)', $sql);
        self::assertStringContainsString('KEY `idx_mt_uni_credit_attempt_operation` (`store_id`, `entry_point`, `operation_key_hash`, `state`)', $sql);
        self::assertStringContainsString('KEY `idx_mt_uni_credit_attempt_cart` (`store_id`, `cart_id`, `state`)', $sql);

        self::assertStringNotContainsString('financing_snapshot', $sql);
        self::assertStringNotContainsString('smartucf_log', $sql);
        self::assertStringNotContainsString('mt_uni_credit_token', $sql);
    }

    public function testAttemptStateVocabularyIsCentralized(): void
    {
        self::assertContains(FinancingAttemptState::ISSUED, FinancingAttemptState::all());
        self::assertContains(FinancingAttemptState::TERMINAL_FAILED, FinancingAttemptState::all());
        self::assertTrue(FinancingAttemptState::isValid(FinancingAttemptState::CP_CREATED));
        self::assertFalse(FinancingAttemptState::isValid('popup_processing'));
    }

    public function testOperationEntryPointsAreControlled(): void
    {
        self::assertSame(['product', 'cart', 'checkout'], OperationEntryPoint::all());
        self::assertTrue(OperationEntryPoint::isValid(OperationEntryPoint::CHECKOUT));
        self::assertFalse(OperationEntryPoint::isValid('admin'));
    }

    public function testSecurityConstantsMatchPhase0Contracts(): void
    {
        self::assertSame(900, SecurityConstants::NONCE_RETENTION_SECONDS);
        self::assertSame(45, SecurityConstants::OPERATION_LOCK_TTL_SECONDS);
        self::assertSame(64, SecurityConstants::NONCE_HEX_LENGTH);
    }
}
