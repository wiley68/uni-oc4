<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\DiagnosticPayloadRedactor;
use Opencart\System\Library\Extension\MtUniCredit\ShopSnapshotSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Regression: apostrophe free-text must round-trip unchanged through OC4 persistence helpers.
 */
final class ApostrophePersistenceRegressionTest extends TestCase
{
    private const PRODUCT_NAME = "Test Product's Name";

    public function testSanitizeLogMessageRedactsSecretsAndSqlFragments(): void
    {
        $raw = "secret=abc123 token=xyz egn=1234567890 phone2=0888123456 INSERT INTO t VALUES ('O''Brien')";
        $sanitized = DiagnosticPayloadRedactor::sanitizeLogMessage($raw);
        self::assertStringNotContainsString('abc123', $sanitized);
        self::assertStringNotContainsString('xyz', $sanitized);
        self::assertStringContainsString('[REDACTED]', $sanitized);
        self::assertStringContainsString('[sql-redacted]', $sanitized);
    }

    public function testShopSnapshotSanitizerPreservesApostropheInSafeFields(): void
    {
        $sanitized = ShopSnapshotSanitizer::sanitize([
            'shop_name' => "O'Brien Store",
            'product_label' => self::PRODUCT_NAME,
            'uni_password' => "p'ass",
            'refresh_token' => 'strip-me',
        ]);
        self::assertSame("O'Brien Store", $sanitized['shop_name']);
        self::assertSame(self::PRODUCT_NAME, $sanitized['product_label']);
        self::assertSame("p'ass", $sanitized['uni_password']);
        self::assertArrayNotHasKey('refresh_token', $sanitized);
    }

    public function testCpPayloadJsonRoundTripPreservesApostrophe(): void
    {
        $payload = [
            'order_id' => '12345',
            'products_name' => self::PRODUCT_NAME,
            'name' => "Client O'Neil",
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::PRODUCT_NAME, $decoded['products_name']);
        self::assertSame("Client O'Neil", $decoded['name']);
        self::assertStringContainsString("'", $decoded['products_name']);
    }

    public function testFinancingPresentationAndDiagnosticJsonPreserveApostrophe(): void
    {
        $presentation = ['scheme_label' => self::PRODUCT_NAME, 'note' => "It's fine"];
        $diagnostic = ['summary' => self::PRODUCT_NAME, 'token' => 'should-redact'];
        $presentationJson = json_encode($presentation, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $diagnosticJson = json_encode($diagnostic, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $presentationBack = json_decode($presentationJson, true, 512, JSON_THROW_ON_ERROR);
        $diagnosticBack = DiagnosticPayloadRedactor::redact(
            json_decode($diagnosticJson, true, 512, JSON_THROW_ON_ERROR)
        );
        self::assertSame(self::PRODUCT_NAME, $presentationBack['scheme_label']);
        self::assertSame(self::PRODUCT_NAME, $diagnosticBack['summary']);
        self::assertSame('[REDACTED]', $diagnosticBack['token']);
    }
}
