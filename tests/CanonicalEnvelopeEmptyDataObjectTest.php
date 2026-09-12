<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\InboundApiDispatcher;
use Opencart\System\Library\Extension\MtUniCredit\InboundApiEnvelope;
use Opencart\System\Library\Extension\MtUniCredit\ModuleApiException;
use PHPUnit\Framework\TestCase;

/**
 * REVIEW-01 — empty envelope data must serialize as JSON object `{}`, never `[]`.
 */
final class CanonicalEnvelopeEmptyDataObjectTest extends TestCase
{
    /** @return list<array{0:int,1:string}> */
    public static function statusProvider(): array
    {
        return [
            [200, 'ok'],
            [400, 'bad_request'],
            [401, 'authentication_failed'],
            [404, 'not_found'],
            [409, 'conflict'],
            [413, 'payload_too_large'],
            [422, 'unprocessable_entity'],
            [500, 'internal_error'],
        ];
    }

    /**
     * @dataProvider statusProvider
     */
    public function testEncodedEnvelopeUsesObjectData(int $status, string $error): void
    {
        if ($status < 400) {
            $encoded = InboundApiDispatcher::encodeResponse(
                InboundApiEnvelope::success('OK'),
                $status
            );
        } else {
            $encoded = InboundApiDispatcher::encodeException(
                new ModuleApiException('Nope', $status, $error)
            );
        }

        self::assertStringContainsString('"data":{}', $encoded['body']);
        self::assertStringNotContainsString('"data":[]', $encoded['body']);

        $decoded = json_decode($encoded['body'], false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $decoded);
        self::assertInstanceOf(\stdClass::class, $decoded->data);
        self::assertTrue(property_exists($decoded, 'success'));
        self::assertTrue(property_exists($decoded, 'error'));
        self::assertTrue(property_exists($decoded, 'message'));
        self::assertTrue(property_exists($decoded, 'data'));
    }

    public function testLogoutNoTokenShapeUsesObjectData(): void
    {
        $encoded = InboundApiDispatcher::encodeResponse(
            InboundApiEnvelope::success('Logged out locally.'),
            200
        );
        self::assertStringContainsString('"data":{}', $encoded['body']);
        self::assertSame(
            '{"success":true,"error":null,"message":"Logged out locally.","data":{}}',
            $encoded['body']
        );
    }
}
