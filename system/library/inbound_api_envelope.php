<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Canonical CP↔module JSON envelopes for inbound responses.
 *
 * Empty `data` is always a JSON object `{}` (stdClass), never a JSON array `[]`.
 */
final class InboundApiEnvelope
{
    /**
     * @param array<string, mixed>|null $data
     * @return array{success: bool, error: null, message: string, data: array<string, mixed>|\stdClass}
     */
    public static function success(string $message, ?array $data = null): array
    {
        return [
            'success' => true,
            'error' => null,
            'message' => $message,
            'data' => self::objectData($data),
        ];
    }

    /**
     * @param array<string, mixed>|null $data
     * @return array{success: bool, error: string, message: string, data: array<string, mixed>|\stdClass}
     */
    public static function failure(string $error, string $message, ?array $data = null): array
    {
        $error = trim($error);
        if ($error === '' || !preg_match('/\A[a-z0-9_]+\z/', $error)) {
            $error = 'internal_error';
        }

        return [
            'success' => false,
            'error' => $error,
            'message' => $message,
            'data' => self::objectData($data),
        ];
    }

    /**
     * Normalize a handler/exception payload into the canonical four-field envelope.
     *
     * @param array<string, mixed> $payload
     * @return array{success: bool, error: string|null, message: string, data: array<string, mixed>|\stdClass}
     */
    public static function normalize(array $payload, bool $successDefault = true): array
    {
        $success = array_key_exists('success', $payload)
            ? (bool) $payload['success']
            : $successDefault;

        $message = isset($payload['message']) && is_string($payload['message'])
            ? $payload['message']
            : ($success ? 'OK' : 'Модулът не можа да обработи заявката.');

        $data = self::objectData(
            isset($payload['data']) && is_array($payload['data'])
                ? $payload['data']
                : null
        );

        if ($success) {
            return [
                'success' => true,
                'error' => null,
                'message' => $message,
                'data' => $data,
            ];
        }

        $error = isset($payload['error']) && is_string($payload['error']) && $payload['error'] !== ''
            ? $payload['error']
            : 'internal_error';

        return self::failure($error, $message, is_array($data) ? $data : null);
    }

    /**
     * Ensure encode-ready payload: empty data is always `{}`.
     *
     * @param array<string, mixed> $envelope
     * @return array{success: bool, error: string|null, message: string, data: array<string, mixed>|\stdClass}
     */
    public static function forJsonEncode(array $envelope, bool $successDefault = true): array
    {
        $normalized = self::normalize($envelope, $successDefault);
        if ($normalized['data'] instanceof \stdClass) {
            return $normalized;
        }
        if (is_array($normalized['data']) && $normalized['data'] === []) {
            $normalized['data'] = new \stdClass();
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>|\stdClass
     */
    private static function objectData(?array $data): array|\stdClass
    {
        if ($data === null || $data === []) {
            return new \stdClass();
        }

        if (array_is_list($data)) {
            // Canonical contract requires a JSON object, never a top-level list.
            return new \stdClass();
        }

        return $data;
    }
}
