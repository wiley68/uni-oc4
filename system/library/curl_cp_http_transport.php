<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class CurlCpHttpTransport implements CpHttpTransport
{
    private int $connectTimeout;

    private int $timeout;

    public function __construct(
        int $connectTimeout = CpHttpConstants::CONNECT_TIMEOUT_SECONDS,
        int $timeout = CpHttpConstants::TOTAL_TIMEOUT_SECONDS
    ) {
        $this->connectTimeout = $connectTimeout;
        $this->timeout = $timeout;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $payload
     */
    public function request(string $method, string $url, array $headers, ?array $payload): CpHttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new CpConnectionException('The cURL PHP extension is not available.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new CpConnectionException('The Control Panel request could not be initialized.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headerLines,
        ];

        if ($payload !== null) {
            try {
                $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                curl_close($handle);
                throw new CpConnectionException('The Control Panel request payload could not be encoded.', 0, $exception);
            }
        }

        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);

        if ($body === false) {
            $errorNumber = curl_errno($handle);
            $error = curl_error($handle);
            curl_close($handle);

            if ($errorNumber === CURLE_OPERATION_TIMEDOUT) {
                throw new CpTimeoutException('The Control Panel request timed out.');
            }

            throw new CpConnectionException('The Control Panel connection failed: ' . $error);
        }

        if (strlen((string) $body) > CpHttpConstants::MAX_RESPONSE_BYTES) {
            curl_close($handle);
            throw new CpInvalidPayloadException('The Control Panel response exceeded the allowed size.');
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new CpHttpResponse($statusCode, (string) $body);
    }
}
