<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\DeploymentHealthService;
use Opencart\System\Library\Extension\MtUniCredit\DeploymentHealthStatus;
use PHPUnit\Framework\TestCase;

final class Phase2AdminHealthPresenterTest extends TestCase
{
    public function testHealthSummaryExposesStatusesWithoutSecrets(): void
    {
        $health = (new DeploymentHealthService())->evaluate();
        $encoded = json_encode($health);
        self::assertIsString($encoded);

        self::assertArrayHasKey('environment', $health);
        self::assertArrayHasKey('control_panel', $health);
        self::assertArrayHasKey('secrets', $health);
        self::assertArrayHasKey('certificate', $health);
        self::assertArrayHasKey('private_key', $health);
        self::assertArrayHasKey('certificate_validity', $health);
        self::assertArrayHasKey('certificate_key_match', $health);
        self::assertArrayHasKey('deployment_ready', $health);

        self::assertContains($health['environment']['status'], DeploymentHealthStatus::all());
        self::assertContains($health['secrets']['status'], DeploymentHealthStatus::all());

        self::assertStringNotContainsString('BEGIN CERTIFICATE', $encoded);
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', $encoded);
        self::assertStringNotContainsString('BEGIN ENCRYPTED', $encoded);
        self::assertStringNotContainsString('passphrase', $encoded);
        self::assertArrayNotHasKey('passphrase', $health['secrets']);
        self::assertArrayNotHasKey('value', $health['secrets']);

        if (!empty($health['control_panel']['host'])) {
            self::assertStringNotContainsString('://', (string) $health['control_panel']['host']);
        }
    }

    public function testTwigDoesNotRenderSensitiveMaterialFields(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__) . '/admin/view/template/module/mt_uni_credit.twig');
        self::assertStringContainsString('health.secrets_status_label', $twig);
        self::assertStringContainsString('health.certificate_status_label', $twig);
        self::assertStringContainsString('health.control_panel_host', $twig);
        self::assertStringNotContainsString('passphrase', strtolower($twig));
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', $twig);
        self::assertStringNotContainsString('name="control_panel_url"', $twig);
    }

    public function testIsDeploymentReadyNeverThrows(): void
    {
        $service = new DeploymentHealthService();
        self::assertIsBool($service->isDeploymentReady());
    }
}
