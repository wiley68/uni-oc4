<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\InboundApiDispatcher;
use Opencart\System\Library\Extension\MtUniCredit\InboundBankStatusVocabulary;
use Opencart\System\Library\Extension\MtUniCredit\ModuleApiException;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ModuleRequestAuthenticator;
use Opencart\System\Library\Extension\MtUniCredit\ModuleRequestSignatureProtocol;
use Opencart\System\Library\Extension\MtUniCredit\ModuleRequestSignatureVerifier;
use Opencart\System\Library\Extension\MtUniCredit\DiagnosticPayloadRedactor;
use PHPUnit\Framework\TestCase;

final class BridgeAInboundApiContractTest extends TestCase
{
    public function testFrozenProductionUrlsAndControllersExist(): void
    {
        $root = dirname(__DIR__);
        self::assertFileExists($root . '/catalog/controller/api/shop_cache.php');
        self::assertFileExists($root . '/catalog/controller/api/order_bank_status.php');
        self::assertFileExists($root . '/catalog/controller/api/smartucf_debug_log.php');
        self::assertFileExists($root . '/docs/INBOUND-API.md');

        $doc = (string) file_get_contents($root . '/docs/INBOUND-API.md');
        self::assertStringContainsString(
            'https://open40.avalonbg.com/index.php?route=extension/mt_uni_credit/api/shop_cache',
            $doc
        );
        self::assertStringContainsString(
            'https://open40.avalonbg.com/index.php?route=extension/mt_uni_credit/api/order_bank_status',
            $doc
        );
        self::assertStringContainsString(
            'https://open40.avalonbg.com/index.php?route=extension/mt_uni_credit/api/smartucf_debug_log',
            $doc
        );
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    public function testHmacMatchesFrozenVector(): void
    {
        $sig = ModuleRequestSignatureProtocol::computeSignature(
            'test_shared_secret_123',
            '1787380000',
            '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
            '{"unicid":"TEST-UNICID","order_id":"ABC123","status":"approved","status_id":"10"}'
        );
        self::assertSame('2f4a55c19a2dd0f2f7f2390a6d720e95dbdff577c096d7ff291ef8f84a53e94f', $sig);
        self::assertSame(300, ModuleRequestSignatureProtocol::TIMESTAMP_TOLERANCE_SECONDS);
        self::assertSame(900, ModuleRequestSignatureProtocol::NONCE_RETENTION_SECONDS);
    }

    public function testVerifierRejectsExpiredTimestamp(): void
    {
        $verifier = new ModuleRequestSignatureVerifier(static fn(): int => 1_787_380_000);
        $raw = '{"unicid":"TEST-UNICID"}';
        $ts = '1787370000'; // >300s skew
        $nonce = str_repeat('a', 64);
        $sig = ModuleRequestSignatureProtocol::computeSignature('secret', $ts, $nonce, $raw);

        $this->expectException(ModuleApiException::class);
        $verifier->verify('secret', $raw, [
            'X-UniPayment-Timestamp' => $ts,
            'X-UniPayment-Nonce' => $nonce,
            'X-UniPayment-Signature' => $sig,
        ]);
    }

    public function testDispatcherRejectsNonPost(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/inbound_api_dispatcher.php'
        );
        self::assertStringContainsString("strtoupper(\$requestMethod) !== 'POST'", $src);
        self::assertStringContainsString('405', $src);

        $exception = null;
        try {
            // Authenticator is type-checked but not invoked until after the POST gate.
            $authenticator = (new \ReflectionClass(ModuleRequestAuthenticator::class))
                ->newInstanceWithoutConstructor();
            InboundApiDispatcher::dispatch(
                static fn(array $p, string $u): array => ['success' => true],
                $authenticator,
                [],
                '{}',
                'GET'
            );
        } catch (ModuleApiException $caught) {
            $exception = $caught;
        }

        self::assertInstanceOf(ModuleApiException::class, $exception);
        self::assertSame(405, $exception->getStatusCode());
    }

    public function testBankStatusVocabulary(): void
    {
        self::assertTrue(InboundBankStatusVocabulary::isAccepted('cp_sent'));
        self::assertTrue(InboundBankStatusVocabulary::isAccepted('bank_sent_process1'));
        self::assertTrue(InboundBankStatusVocabulary::isAccepted('bank_sent_process2'));
        self::assertTrue(InboundBankStatusVocabulary::isAccepted('bank_send_failed_smartucf'));
        self::assertTrue(InboundBankStatusVocabulary::isAccepted('10'));
        self::assertFalse(InboundBankStatusVocabulary::isAccepted('totally_arbitrary_junk'));
        self::assertFalse(InboundBankStatusVocabulary::isAccepted(''));
    }

    public function testDiagnosticRedaction(): void
    {
        $redacted = DiagnosticPayloadRedactor::redact([
            'ok' => true,
            'egn' => '1234567890',
            'email' => 'a@b.c',
            'nested' => ['phone' => '0888', 'code' => 'x'],
        ]);
        self::assertSame('[REDACTED]', $redacted['egn']);
        self::assertSame('[REDACTED]', $redacted['email']);
        self::assertSame('[REDACTED]', $redacted['nested']['phone']);
        self::assertSame('x', $redacted['nested']['code']);
        self::assertTrue($redacted['ok']);
    }

    public function testNoPhase11ExecutionInInboundControllers(): void
    {
        foreach ([
            dirname(__DIR__) . '/catalog/controller/api/shop_cache.php',
            dirname(__DIR__) . '/catalog/controller/api/order_bank_status.php',
            dirname(__DIR__) . '/catalog/controller/api/smartucf_debug_log.php',
            dirname(__DIR__) . '/system/library/module_request_authenticator.php',
        ] as $file) {
            $src = (string) file_get_contents($file);
            self::assertStringNotContainsString('sucfOnlineSessionStart', $src);
            self::assertStringNotContainsString('Process1Execution', $src);
            self::assertStringNotContainsString('bank redirect', $src);
        }
    }

    public function testBridgeATablesRegistered(): void
    {
        $names = \Opencart\System\Library\Extension\MtUniCredit\PersistenceTableNames::allPersistenceTables();
        self::assertContains('mt_uni_credit_order_bank_status', $names);
        self::assertContains('mt_uni_credit_diagnostic_debug_log', $names);
    }
}
