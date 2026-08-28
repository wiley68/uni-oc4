<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

interface CpHttpTransport
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $payload
     */
    public function request(string $method, string $url, array $headers, ?array $payload): CpHttpResponse;
}
