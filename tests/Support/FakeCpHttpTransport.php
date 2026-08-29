<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use Opencart\System\Library\Extension\MtUniCredit\CpHttpResponse;
use Opencart\System\Library\Extension\MtUniCredit\CpHttpTransport;

final class FakeCpHttpTransport implements CpHttpTransport
{
    /** @var list<array{status?: int, body?: string, timeout?: bool, connection?: bool}> */
    private array $responses = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, payload: ?array<string, mixed>}> */
    public array $requests = [];

    private ?int $autoCreateOrderId = null;

    public function enableAutoAuthAndCreate(int $cpOrderId = 901): void
    {
        $this->autoCreateOrderId = $cpOrderId;
    }

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

    public function enqueueTimeout(): void
    {
        $this->responses[] = ['timeout' => true];
    }

    public function enqueueConnectionFailure(): void
    {
        $this->responses[] = ['connection' => true];
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

        if ($this->responses !== []) {
            $next = array_shift($this->responses);
            if (!empty($next['timeout'])) {
                throw new \Opencart\System\Library\Extension\MtUniCredit\CpTimeoutException('Fake Control Panel timeout.');
            }
            if (!empty($next['connection'])) {
                throw new \Opencart\System\Library\Extension\MtUniCredit\CpConnectionException('Fake Control Panel connection failure.');
            }

            return new CpHttpResponse((int) $next['status'], (string) $next['body']);
        }

        if ($this->autoCreateOrderId !== null) {
            if (str_contains($url, '/auth/login') || str_contains($url, '/auth/refresh')) {
                return new CpHttpResponse(200, json_encode(Phase4TestHarness::loginSuccessPayload(), JSON_THROW_ON_ERROR));
            }
            if (str_contains($url, '/orders') && strtoupper($method) === 'POST') {
                return new CpHttpResponse(201, json_encode([
                    'success' => true,
                    'message' => 'Поръчката е създадена успешно',
                    'data' => [
                        'id' => $this->autoCreateOrderId,
                        'shop_id' => 1,
                        'created_at' => '2026-01-01 00:00:00',
                    ],
                ], JSON_THROW_ON_ERROR));
            }
            if (str_contains($url, '/shop')) {
                return new CpHttpResponse(200, json_encode([
                    'success' => true,
                    'data' => mt_uni_credit_valid_shop_snapshot(),
                ], JSON_THROW_ON_ERROR));
            }
        }

        throw new \RuntimeException('FakeCpHttpTransport has no queued response for ' . $method . ' ' . $url);
    }

    public function countOrderCreates(): int
    {
        $count = 0;
        foreach ($this->requests as $request) {
            if (strtoupper($request['method']) === 'POST' && str_contains($request['url'], '/orders')) {
                $count++;
            }
        }

        return $count;
    }
}
