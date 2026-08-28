<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FixtureLoader;
use PHPUnit\Framework\TestCase;

final class HmacContractTest extends TestCase
{
    public function testKnownCallbackVectorMatchesSha256Hmac(): void
    {
        $fixture = FixtureLoader::load('hmac_callback_vector.json');
        $vector = $fixture['vector'];

        $canonical = $vector['timestamp'] . "\n" . $vector['nonce'] . "\n" . $vector['raw_body'];
        $signature = hash_hmac('sha256', $canonical, $vector['secret']);

        self::assertSame('sha256', $fixture['algorithm']);
        self::assertSame(
            'timestamp + "\n" + nonce + "\n" + exact_raw_body',
            $fixture['canonical_form']
        );
        self::assertSame('X-UniPayment-Timestamp', $fixture['headers']['timestamp']);
        self::assertSame('X-UniPayment-Nonce', $fixture['headers']['nonce']);
        self::assertSame('X-UniPayment-Signature', $fixture['headers']['signature']);
        self::assertSame(64, strlen($vector['nonce']));
        self::assertSame(1, preg_match('/^[0-9a-fA-F]{64}$/', $vector['nonce']));
        self::assertSame($fixture['vector']['expected_sha256_hmac'], $signature);
        self::assertDoesNotMatchRegularExpression('/prod|live|avalon/i', $vector['secret']);
    }

    public function testReencodedJsonBodyDoesNotMatchFrozenSignature(): void
    {
        $fixture = FixtureLoader::load('hmac_callback_vector.json');
        $vector = $fixture['vector'];
        $decoded = json_decode($vector['raw_body'], true, 512, JSON_THROW_ON_ERROR);
        $pretty = json_encode($decoded, JSON_PRETTY_PRINT);
        self::assertNotSame($vector['raw_body'], $pretty);

        $canonical = $vector['timestamp'] . "\n" . $vector['nonce'] . "\n" . $pretty;
        $signature = hash_hmac('sha256', $canonical, $vector['secret']);
        self::assertNotSame($vector['expected_sha256_hmac'], $signature);
    }

    public function testReplayWindowAndNonceRulesAreFrozen(): void
    {
        $rules = FixtureLoader::load('hmac_callback_vector.json')['rules'];
        self::assertSame(300, $rules['timestamp_tolerance_seconds']);
        self::assertSame(64, $rules['nonce_hex_length']);
        self::assertSame(900, $rules['nonce_retention_seconds']);
        self::assertSame('sha256(nonce)', $rules['nonce_stored_as']);
        self::assertSame('lowercase hex', $rules['signature_encoding']);
    }
}
