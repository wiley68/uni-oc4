<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use Opencart\System\Library\Extension\MtUniCredit\CpHttpResponse;
use Opencart\System\Library\Extension\MtUniCredit\CpHttpTransport;

final class FakeCpHttpTransport implements CpHttpTransport
{
    /** @var list<array{status: int, body: string}> */
    private array $responses = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, payload: ?array<string, mixed>}> */
    public array $requests = [];

    public function enqueue(int $status, string $body): void
    {
        $this->responses[] = ['status' => $status, 'body' => $body];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueueJson(int $status, array $payload): void
    {
        $this->enqueue($status, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $payload
     */
    public function request(string $method, string $url, array $headers, ?array $payload): CpHttpResponse
    {
        $this->requests[] = [
            'method'  => $method,
            'url'     => $url,
            'headers' => $headers,
            'payload' => $payload,
        ];

        if ($this->responses === []) {
            throw new \RuntimeException('FakeCpHttpTransport has no queued response.');
        }

        $next = array_shift($this->responses);

        return new CpHttpResponse($next['status'], $next['body']);
    }
}
