<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Shared POST/JSON/HMAC lifecycle for catalog inbound CP API controllers.
 */
final class InboundApiDispatcher
{
    /**
     * @param callable(array<string, mixed>, string): array<string, mixed> $handler
     * @param array<string, mixed> $server
     */
    public static function dispatch(
        callable $handler,
        ModuleRequestAuthenticator $authenticator,
        array $server,
        string $rawBody,
        string $requestMethod,
        ?string $expectedOperation = null
    ): array {
        if (strtoupper($requestMethod) !== 'POST') {
            throw new ModuleApiException('Разрешени са само POST заявки.', 405);
        }

        if ($rawBody === '') {
            throw new ModuleApiException('Изисква се JSON тяло на заявката.', 400);
        }

        $headers = self::extractHeaders($server);
        [$payload, $unicid] = $authenticator->authenticate($rawBody, $headers);

        if ($expectedOperation !== null) {
            InboundApiOperations::assertExact($payload, $expectedOperation);
        }

        return $handler($payload, $unicid);
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    public static function extractHeaders(array $server): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            $requestHeaders = getallheaders();
            if (is_array($requestHeaders)) {
                foreach ($requestHeaders as $name => $value) {
                    if (is_string($name) && is_string($value)) {
                        $headers[$name] = $value;
                    }
                }
            }
        }

        foreach ($server as $key => $value) {
            if (!is_string($value) || !str_starts_with((string) $key, 'HTTP_')) {
                continue;
            }
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) $key, 5)))));
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string}
     */
    public static function encodeResponse(array $payload, int $statusCode): array
    {
        $normalized = InboundApiEnvelope::forJsonEncode($payload, $statusCode < 400);

        try {
            $body = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $fallback = InboundApiEnvelope::forJsonEncode(
                InboundApiEnvelope::failure('internal_error', 'Модулът не можа да кодира отговора.'),
                false
            );

            return [
                'status' => 500,
                'body' => (string) json_encode(
                    $fallback,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ];
        }

        return ['status' => $statusCode, 'body' => (string) $body];
    }

    /**
     * @return array{status: int, body: string}
     */
    public static function encodeException(ModuleApiException $exception): array
    {
        $error = $exception->getErrorCode();
        if ($error === null || $error === '') {
            $error = match ($exception->getStatusCode()) {
                400 => 'bad_request',
                401 => 'authentication_failed',
                403 => 'module_disabled',
                404 => 'not_found',
                405 => 'method_not_allowed',
                409 => 'conflict',
                413 => 'payload_too_large',
                422 => 'unprocessable_entity',
                default => 'internal_error',
            };
        }

        return self::encodeResponse(
            InboundApiEnvelope::failure(
                $error,
                $exception->getMessage(),
                $exception->getResponseData()
            ),
            $exception->getStatusCode()
        );
    }

    public static function httpStatusLine(int $status): string
    {
        return match ($status) {
            200 => '200 OK',
            201 => '201 Created',
            400 => '400 Bad Request',
            401 => '401 Unauthorized',
            403 => '403 Forbidden',
            404 => '404 Not Found',
            405 => '405 Method Not Allowed',
            409 => '409 Conflict',
            413 => '413 Payload Too Large',
            422 => '422 Unprocessable Entity',
            500 => '500 Internal Server Error',
            default => $status . ' Error',
        };
    }
}
