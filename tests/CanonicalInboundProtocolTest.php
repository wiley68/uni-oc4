<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\BoundedRawBodyReader;
use Opencart\System\Library\Extension\MtUniCredit\DiagnosticPayloadRedactor;
use Opencart\System\Library\Extension\MtUniCredit\InboundApiDispatcher;
use Opencart\System\Library\Extension\MtUniCredit\InboundApiEnvelope;
use Opencart\System\Library\Extension\MtUniCredit\InboundApiOperations;
use Opencart\System\Library\Extension\MtUniCredit\ModuleApiException;
use Opencart\System\Library\Extension\MtUniCredit\ModuleRequestSignatureProtocol;
use Opencart\System\Library\Extension\MtUniCredit\ModuleRequestSignatureVerifier;
use Opencart\System\Library\Extension\MtUniCredit\ShopSnapshotSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Canonical inbound CP→module protocol suites (envelope, ops, nonce, body gate, sanitizer).
 */
final class CanonicalInboundProtocolTest extends TestCase
{
    public function testEnvelopeAlwaysHasFourFieldsAndObjectData(): void
    {
        $ok = InboundApiEnvelope::success('OK', ['a' => 1]);
        self::assertTrue($ok['success']);
        self::assertNull($ok['error']);
        self::assertSame('OK', $ok['message']);
        self::assertSame(['a' => 1], $ok['data']);

        $fail = InboundApiEnvelope::failure('bad_request', 'Nope', null);
        self::assertFalse($fail['success']);
        self::assertSame('bad_request', $fail['error']);
        self::assertInstanceOf(\stdClass::class, $fail['data']);

        $normalized = InboundApiEnvelope::normalize(['success' => true, 'message' => 'x'], true);
        self::assertNull($normalized['error']);
        self::assertInstanceOf(\stdClass::class, $normalized['data']);

        // Lists are never accepted as data objects.
        $listRejected = InboundApiEnvelope::success('OK', [1, 2, 3]);
        self::assertInstanceOf(\stdClass::class, $listRejected['data']);
    }

    public function testOperationConstantsAndAssertExact(): void
    {
        self::assertSame([
            InboundApiOperations::SHOP_CACHE,
            InboundApiOperations::ORDER_BANK_STATUS,
            InboundApiOperations::SMARTUCF_DEBUG_LOG,
        ], InboundApiOperations::all());

        InboundApiOperations::assertExact(['operation' => 'shop-cache'], InboundApiOperations::SHOP_CACHE);

        $this->expectException(ModuleApiException::class);
        InboundApiOperations::assertExact(['operation' => 'shop-cache'], InboundApiOperations::ORDER_BANK_STATUS);
    }

    public function testNonceLowercaseOnlyAndExtractDoesNotStrtolower(): void
    {
        $verifier = new ModuleRequestSignatureVerifier(static fn(): int => 1_787_380_000);
        $raw = '{"unicid":"TEST"}';
        $ts = '1787380000';
        $nonce = str_repeat('a', 64);
        $sig = ModuleRequestSignatureProtocol::computeSignature('secret', $ts, $nonce, $raw);
        $headers = [
            'X-UniPayment-Timestamp' => $ts,
            'X-UniPayment-Nonce' => $nonce,
            'X-UniPayment-Signature' => $sig,
        ];
        $verifier->verify('secret', $raw, $headers);
        self::assertSame($nonce, $verifier->extractNonce($headers));

        $upper = str_repeat('A', 64);
        $this->expectException(ModuleApiException::class);
        $verifier->verify('secret', $raw, [
            'X-UniPayment-Timestamp' => $ts,
            'X-UniPayment-Nonce' => $upper,
            'X-UniPayment-Signature' => ModuleRequestSignatureProtocol::computeSignature('secret', $ts, $upper, $raw),
        ]);
    }

    public function testBoundedBodyReaderDetectsOversized(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, str_repeat('x', 100));
        rewind($stream);
        $result = BoundedRawBodyReader::read($stream, 50);
        fclose($stream);
        self::assertTrue($result['oversized']);
        self::assertSame('', $result['body']);
    }

    public function testShopSnapshotSanitizerKeepsKnownCredentialsStripsUnknownSecrets(): void
    {
        $sanitized = ShopSnapshotSanitizer::sanitize([
            'uni_user' => 'u',
            'uni_password' => 'p',
            'api_key' => 'should-strip',
            'safe_field' => 'keep',
        ]);
        self::assertSame('u', $sanitized['uni_user']);
        self::assertSame('p', $sanitized['uni_password']);
        self::assertSame('keep', $sanitized['safe_field']);
        self::assertArrayNotHasKey('api_key', $sanitized);
    }

    public function testEncodeExceptionUsesCanonicalEnvelope(): void
    {
        $encoded = InboundApiDispatcher::encodeException(
            new ModuleApiException('Nope', 401)
        );
        self::assertSame(401, $encoded['status']);
        $decoded = json_decode($encoded['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($decoded['success']);
        self::assertSame('authentication_failed', $decoded['error']);
        self::assertSame('Nope', $decoded['message']);
        self::assertIsArray($decoded['data']);
    }

    public function testHttpStatusLineIncludesConflictAndPayloadTooLarge(): void
    {
        self::assertSame('409 Conflict', InboundApiDispatcher::httpStatusLine(409));
        self::assertSame('413 Payload Too Large', InboundApiDispatcher::httpStatusLine(413));
        self::assertSame('201 Created', InboundApiDispatcher::httpStatusLine(201));
        self::assertSame('422 Unprocessable Entity', InboundApiDispatcher::httpStatusLine(422));
    }

    public function testControllersBindExpectedOperations(): void
    {
        foreach ([
            dirname(__DIR__) . '/catalog/controller/api/shop_cache.php' => 'SHOP_CACHE',
            dirname(__DIR__) . '/catalog/controller/api/order_bank_status.php' => 'ORDER_BANK_STATUS',
            dirname(__DIR__) . '/catalog/controller/api/smartucf_debug_log.php' => 'SMARTUCF_DEBUG_LOG',
        ] as $file => $const) {
            $src = (string) file_get_contents($file);
            self::assertStringContainsString('expectedOperation()', $src);
            self::assertStringContainsString('InboundApiOperations::' . $const, $src);
        }
    }

    public function testTimestampToleranceInclusiveBoundaries(): void
    {
        $now = 1_787_380_000;
        $verifier = new ModuleRequestSignatureVerifier(static fn(): int => $now);
        $raw = '{"unicid":"TEST"}';
        $nonce = str_repeat('b', 64);

        foreach ([300, -300] as $delta) {
            $ts = (string) ($now + $delta);
            $sig = ModuleRequestSignatureProtocol::computeSignature('secret', $ts, $nonce, $raw);
            $verifier->verify('secret', $raw, [
                'X-UniPayment-Timestamp' => $ts,
                'X-UniPayment-Nonce' => $nonce,
                'X-UniPayment-Signature' => $sig,
            ]);
        }

        $this->expectException(ModuleApiException::class);
        $ts = (string) ($now + 301);
        $verifier->verify('secret', $raw, [
            'X-UniPayment-Timestamp' => $ts,
            'X-UniPayment-Nonce' => $nonce,
            'X-UniPayment-Signature' => ModuleRequestSignatureProtocol::computeSignature('secret', $ts, $nonce, $raw),
        ]);
    }

    public function testMixedCaseNonceRejected(): void
    {
        $verifier = new ModuleRequestSignatureVerifier(static fn(): int => 1_787_380_000);
        $raw = '{"unicid":"TEST"}';
        $ts = '1787380000';
        $nonce = str_repeat('abCD', 16);
        $this->expectException(ModuleApiException::class);
        $verifier->verify('secret', $raw, [
            'X-UniPayment-Timestamp' => $ts,
            'X-UniPayment-Nonce' => $nonce,
            'X-UniPayment-Signature' => ModuleRequestSignatureProtocol::computeSignature('secret', $ts, $nonce, $raw),
        ]);
    }

    public function testBoundedBodyExactLimitAcceptedAndMaxPlusOneRejected(): void
    {
        $exact = str_repeat('y', 64);
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, $exact);
        rewind($stream);
        $ok = BoundedRawBodyReader::read($stream, 64);
        fclose($stream);
        self::assertFalse($ok['oversized']);
        self::assertSame($exact, $ok['body']);

        $stream2 = fopen('php://memory', 'r+');
        self::assertNotFalse($stream2);
        fwrite($stream2, str_repeat('z', 65));
        rewind($stream2);
        $over = BoundedRawBodyReader::read($stream2, 64);
        fclose($stream2);
        self::assertTrue($over['oversized']);
    }

    public function testSmartucfDebugRequiresProcess1OwnershipGates(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/api/smartucf_debug_log.php');
        self::assertStringContainsString('isAuthorizedPrimaryDebugTarget', $src);
        self::assertStringContainsString('BankStatus::SENT_PROCESS2', $src);
        self::assertStringContainsString('SmartUcfLifecycleStates::NOT_STARTED', $src);
        self::assertStringContainsString('opaqueNotFound', $src);
    }

    public function testLoggerSanitizeRedactsSensitiveFragments(): void
    {
        $msg = DiagnosticPayloadRedactor::sanitizeLogMessage(
            'token=abc123 egn=1234567890 phone2=0888111222 secret=shh -----BEGIN CERTIFICATE-----\nabc\n-----END CERTIFICATE-----'
        );
        self::assertStringNotContainsString('abc123', $msg);
        self::assertStringNotContainsString('1234567890', $msg);
        self::assertStringNotContainsString('0888111222', $msg);
        self::assertStringContainsString('[REDACTED]', $msg);
        self::assertStringContainsString('[redacted-pem]', $msg);
    }
}
