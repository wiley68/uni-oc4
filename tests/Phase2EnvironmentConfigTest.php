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
        self::assertSame(parse_url($url, PHP_URL_HOST), $env->controlPanelHost());
        self::assertSame($url . '/api/v1', $env->controlPanelApiBaseUrl());
        self::assertFileExists(dirname(__DIR__) . '/config/environment.php');
    }

    public function testInvalidEnvironmentFileIsRejected(): void
    {
        $result = \MtUniCredit\Tests\Support\IsolatedCpRuntime::run(<<<'PHP'
try {
    (new U\ModuleDeploymentEnvironment())->controlPanelUrl();
    echo json_encode(false);
} catch (RuntimeException) {
    echo json_encode(true);
}
PHP, 'not-a-url');
        self::assertTrue($result);
    }

    public function testMissingEnvironmentFileIsRejected(): void
    {
        $result = \MtUniCredit\Tests\Support\IsolatedCpRuntime::run(<<<'PHP'
unlink(U\ExtensionRoot::path() . '/config/environment.php');
try {
    (new U\ModuleDeploymentEnvironment())->controlPanelUrl();
    echo json_encode(false);
} catch (RuntimeException) {
    echo json_encode(true);
}
PHP);
        self::assertTrue($result);
    }

    public function testApiPathPrefixIsCentralized(): void
    {
        self::assertSame('/api/v1', ModuleDeploymentEnvironment::API_PATH_PREFIX);
        self::assertSame('control_panel_url', ModuleDeploymentEnvironment::CONTROL_PANEL_URL_KEY);
        self::assertSame('config/environment.php', ModuleDeploymentEnvironment::RELATIVE_PATH);
    }
}
