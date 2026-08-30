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
        string $requestMethod
    ): array {
        if (strtoupper($requestMethod) !== 'POST') {
            throw new ModuleApiException('Разрешени са само POST заявки.', 405);
        }

        if ($rawBody === '') {
            throw new ModuleApiException('Изисква се JSON тяло на заявката.', 400);
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ModuleApiException('JSON тялото на заявката е невалидно.', 400);
        }

        if (!is_array($payload)) {
            throw new ModuleApiException('JSON тялото на заявката трябва да бъде обект.', 400);
        }

        $headers = self::extractHeaders($server);
        $unicid = $authenticator->authenticate($payload, $rawBody, $headers);

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
        try {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return [
                'status' => 500,
                'body' => '{"success":false,"message":"Модулът не можа да кодира отговора."}',
            ];
        }

        return ['status' => $statusCode, 'body' => (string) $body];
    }

    public static function encodeException(ModuleApiException $exception): array
    {
        $payload = [
            'success' => false,
            'message' => $exception->getMessage(),
        ];
        if ($exception->getErrorCode() !== null) {
            $payload['error'] = $exception->getErrorCode();
        }
        if ($exception->getResponseData() !== null) {
            $payload['data'] = $exception->getResponseData();
        }

        return self::encodeResponse($payload, $exception->getStatusCode());
    }
}
