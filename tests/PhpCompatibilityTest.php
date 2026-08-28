<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class PhpCompatibilityTest extends TestCase
{
    public function testRuntimeIsPhp84CapableAndNotOlderThan82(): void
    {
        self::assertGreaterThanOrEqual(80200, PHP_VERSION_ID);
    }

    public function testProductionPhpDoesNotRequirePhp84OnlyFeatures(): void
    {
        $root = dirname(__DIR__);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $php84Tokens = [
            'array_find(',
            'array_find_key(',
            'array_any(',
            'array_all(',
            'public private(set)',
            'protected private(set)',
            'http_get_last_response_headers(',
            'request_parse_body(',
        ];
        $php83Tokens = [
            'json_validate(',
            '#[\\Override]',
            '#[Override]',
        ];

        $hits = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (
                str_contains($path, '/vendor/')
                || str_contains($path, '/tests/')
                || str_contains($path, '/stubs/')
                || str_contains($path, '/.phpunit.cache/')
            ) {
                continue;
            }

            $contents = (string) file_get_contents($path);
            foreach (array_merge($php84Tokens, $php83Tokens) as $token) {
                if (str_contains($contents, $token)) {
                    $hits[] = $path . ' contains ' . $token;
                }
            }
        }

        self::assertSame([], $hits);
    }

    public function testComposerPhpConstraintExcludesPhp85AndRequires82(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('>=8.2 <8.5', $composer['require']['php']);
    }
}
