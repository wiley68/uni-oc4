<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\ControlPanelOrderPayloadBuilder;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\ProductFinancingTestHarness;
use PHPUnit\Framework\TestCase;

/**
 * Development cycle: module version stays 2.0.2 until tag/release v2.0.2.
 */
final class ModuleVersionFreezeTest extends TestCase
{
    public function testAuthoritativeModuleVersionIsFrozenAt202(): void
    {
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    public function testInstallJsonMatchesAuthoritativeVersion(): void
    {
        $install = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/install.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(ModuleConstants::VERSION, $install['version']);
        self::assertSame('2.0.2', $install['version']);
    }

    public function testControlPanelPayloadUsesAuthoritativeVersion(): void
    {
        $payload = (new ControlPanelOrderPayloadBuilder())->build(
            OrderMaterializationTestHarness::productSubmission(),
            12345,
            ProductFinancingTestHarness::shop()
        );
        self::assertSame(ModuleConstants::VERSION, $payload['version']);
        self::assertSame('2.0.2', $payload['version']);
    }

    public function testProductionRuntimeDoesNotHardcodeLaterModuleReleaseVersions(): void
    {
        $root = dirname(__DIR__);
        $forbidden = ['2.0.3', '2.0.4'];
        $paths = [
            $root . '/system/library',
            $root . '/admin',
            $root . '/catalog',
            $root . '/install.json',
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $this->assertNoLaterVersion($path, (string) file_get_contents($path), $forbidden);
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, ['php', 'twig', 'json'], true)) {
                    continue;
                }
                $pathname = $file->getPathname();
                // Docs/tests are outside this scan; allow historical comments only in RELEASE/CONTRACTS via docs/.
                $this->assertNoLaterVersion($pathname, (string) file_get_contents($pathname), $forbidden);
            }
        }
    }

    /**
     * @param list<string> $forbidden
     */
    private function assertNoLaterVersion(string $path, string $contents, array $forbidden): void
    {
        foreach ($forbidden as $version) {
            self::assertStringNotContainsString(
                $version,
                $contents,
                $path . ' must not contain active module release version ' . $version
            );
        }
    }
}
