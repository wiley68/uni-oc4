<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

final class FixtureLoader
{
    /** @return array<string, mixed> */
    public static function load(string $filename): array
    {
        $path = dirname(__DIR__) . '/fixtures/' . $filename;
        if (!is_file($path)) {
            throw new \RuntimeException('Missing fixture: ' . $filename);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read fixture: ' . $filename);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Invalid JSON fixture: ' . $filename, 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Fixture is not an object: ' . $filename);
        }

        return $decoded;
    }
}
