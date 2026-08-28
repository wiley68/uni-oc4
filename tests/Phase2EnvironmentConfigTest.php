<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\ModuleDeploymentEnvironment;
use PHPUnit\Framework\TestCase;

final class Phase2EnvironmentConfigTest extends TestCase
{
    public function testPackagedEnvironmentFileLoadsControlPanelUrl(): void
    {
        $env = new ModuleDeploymentEnvironment();
        $url = $env->controlPanelUrl();
        self::assertMatchesRegularExpression('#^https://#i', $url);
        self::assertSame('uni.avalonbg.com', $env->controlPanelHost());
        self::assertSame($url . '/api/v1', $env->controlPanelApiBaseUrl());
        self::assertFileExists(dirname(__DIR__) . '/config/environment.php');
    }

    public function testInvalidEnvironmentFileIsRejected(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'env');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\nreturn ['control_panel_url' => 'not-a-url'];\n");

        $env = new ModuleDeploymentEnvironment($tmp);
        $this->expectException(\RuntimeException::class);
        try {
            $env->controlPanelUrl();
        } finally {
            @unlink($tmp);
        }
    }

    public function testMissingEnvironmentFileIsRejected(): void
    {
        $env = new ModuleDeploymentEnvironment(sys_get_temp_dir() . '/mt-uni-credit-missing-env-' . uniqid('', true) . '.php');
        $this->expectException(\RuntimeException::class);
        $env->controlPanelUrl();
    }

    public function testApiPathPrefixIsCentralized(): void
    {
        self::assertSame('/api/v1', ModuleDeploymentEnvironment::API_PATH_PREFIX);
        self::assertSame('control_panel_url', ModuleDeploymentEnvironment::CONTROL_PANEL_URL_KEY);
        self::assertSame('config/environment.php', ModuleDeploymentEnvironment::RELATIVE_PATH);
    }
}
