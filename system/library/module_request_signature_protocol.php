<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Frozen CP ↔ module inbound HMAC contract (parity with uni-ps9 / uni-woo / CP ModuleRequestSigner).
 */
final class ModuleRequestSignatureProtocol
{
    public const HEADER_TIMESTAMP = 'X-UniPayment-Timestamp';

    public const HEADER_NONCE = 'X-UniPayment-Nonce';

    public const HEADER_SIGNATURE = 'X-UniPayment-Signature';

    public const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public const NONCE_HEX_LENGTH = 64;

    public const NONCE_RETENTION_SECONDS = SecurityConstants::NONCE_RETENTION_SECONDS;

    public const AUTH_FAILURE_MESSAGE = 'Невалидна или изтекла заявка към модула.';

    public static function buildCanonicalString(string $timestamp, string $nonce, string $rawBody): string
    {
        return $timestamp . "\n" . $nonce . "\n" . $rawBody;
    }

    public static function computeSignature(string $secret, string $timestamp, string $nonce, string $rawBody): string
    {
        return hash_hmac(
            'sha256',
            self::buildCanonicalString($timestamp, $nonce, $rawBody),
            $secret
        );
    }
}
