<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Bounded raw HTTP body reader for signed CP→module requests.
 *
 * Reads at most MAX+1 bytes so oversized bodies are detected without unbounded buffering.
 */
final class BoundedRawBodyReader
{
    public const MAX_INBOUND_BYTES = 1048576;

    /**
     * @param resource $stream
     * @return array{body: string, oversized: bool}
     */
    public static function read($stream, int $maxBytes = self::MAX_INBOUND_BYTES): array
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('maxBytes must be non-negative.');
        }

        $limit = $maxBytes + 1;
        $chunks = [];
        $total = 0;

        while (!feof($stream)) {
            $remaining = $limit - $total;
            if ($remaining <= 0) {
                return ['body' => '', 'oversized' => true];
            }

            $chunk = fread($stream, min(8192, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }

            $chunks[] = $chunk;
            $total += strlen($chunk);
            if ($total > $maxBytes) {
                return [
                    'body' => '',
                    'oversized' => true,
                ];
            }
        }

        return [
            'body' => implode('', $chunks),
            'oversized' => false,
        ];
    }

    /**
     * Read php://input with a Content-Length hint that never trusts the header alone.
     *
     * @param array<string, mixed> $server
     * @return array{body: string, oversized: bool}
     */
    public static function readPhpInput(array $server = [], int $maxBytes = self::MAX_INBOUND_BYTES): array
    {
        $contentLength = $server['CONTENT_LENGTH'] ?? $server['HTTP_CONTENT_LENGTH'] ?? null;
        if (is_string($contentLength) || is_int($contentLength)) {
            $declared = (int) $contentLength;
            if ($declared > $maxBytes) {
                return ['body' => '', 'oversized' => true];
            }
        }

        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            return ['body' => '', 'oversized' => false];
        }

        try {
            return self::read($stream, $maxBytes);
        } finally {
            fclose($stream);
        }
    }
}
