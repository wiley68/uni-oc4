<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FixtureLoader;
use PHPUnit\Framework\TestCase;

final class ExtensionMetadataTest extends TestCase
{
    public function testInstallJsonMatchesFrozenExtensionContract(): void
    {
        $expected = FixtureLoader::load('extension_metadata.json');
        $install = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/install.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame($expected['code'], $install['code']);
        self::assertSame($expected['version'], $install['version']);
        self::assertSame($expected['type'], $install['type']);
        self::assertSame($expected['author'], $install['author']);
        self::assertSame([$expected['install_hook_json']], $install['install']);
        self::assertSame([$expected['uninstall_hook_json']], $install['uninstall']);
        self::assertSame(1, $install['status']);
        self::assertSame('2.0.2', $install['version']);
    }

    public function testOpenCartNamespaceDerivationFromExtensionCode(): void
    {
        $code = 'mt_uni_credit';
        $segment = str_replace(['_', '/'], ['', '\\'], ucwords($code, '_/'));
        self::assertSame('MtUniCredit', $segment);
        self::assertSame(
            'Opencart\\Admin\\Controller\\Extension\\MtUniCredit',
            'Opencart\\Admin\\Controller\\Extension\\' . $segment
        );
        self::assertSame(
            'Opencart\\Catalog\\Controller\\Extension\\MtUniCredit',
            'Opencart\\Catalog\\Controller\\Extension\\' . $segment
        );
        self::assertSame('extension/mt_uni_credit/', 'extension/' . $code . '/');
    }

    public function testComposerProductionTargetIsPhp82(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('>=8.2 <8.5', $composer['require']['php']);
        self::assertSame(['php'], array_keys($composer['require']));
        self::assertArrayHasKey('phpunit/phpunit', $composer['require-dev']);
    }
}
