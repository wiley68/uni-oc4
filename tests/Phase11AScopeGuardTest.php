<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

final class Phase11AScopeGuardTest extends TestCase
{
    public function testProcess1CoordinatorNeverWritesProcess2Status(): void
    {
        $coordinator = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/smart_ucf_session_coordinator.php'
        );
        self::assertStringNotContainsString('bank_sent_process2', $coordinator);
        self::assertStringNotContainsString('process2Sent', $coordinator);
        self::assertStringContainsString('BankStatus::process1Sent()', $coordinator);
    }

    public function testSmartUcfClientKeepsTlsVerificationAndUsesCoordinatorLease(): void
    {
        $client = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/smart_ucf_session_client.php'
        );
        self::assertStringContainsString('CURLOPT_SSL_VERIFYPEER => true', $client);
        self::assertStringContainsString('CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS', $client);
        self::assertStringNotContainsString('CertificateSynchronizer', $client);
        self::assertStringContainsString('?CertificateConsumerLease $lease = null', $client);
    }

    public function testControlPanelClientExposesOnlyExpectedCertificateEndpoints(): void
    {
        $client = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/control_panel_client.php'
        );
        self::assertStringContainsString("'/ssl/certificate'", $client);
        self::assertStringContainsString("'/ssl/certificate/bundle'", $client);
        self::assertStringNotContainsString('CertificateSynchronizer', $client);
    }
}
