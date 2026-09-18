<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\BankStatus;
use Opencart\System\Library\Extension\MtUniCredit\SatrudnikFailureNotifier;
use PHPUnit\Framework\TestCase;

/**
 * Satrudnik operational mail on terminal bank_send_failed_* — unit / contract coverage.
 */
final class SatrudnikFailureNotifierTest extends TestCase
{
    public function testAutoloadResolvesSnakeCaseFileViaOc4Rule(): void
    {
        $library = dirname(__DIR__) . '/system/library';
        $ns = 'Opencart\\System\\Library\\Extension\\MtUniCredit\\';
        $expectedFile = $library . '/satrudnik_failure_notifier.php';
        self::assertFileExists($expectedFile);

        $relative = 'SatrudnikFailureNotifier';
        $resolved = $library . '/' . strtolower(preg_replace('~([a-z])([A-Z]|[0-9])~', '\1_\2', $relative)) . '.php';
        self::assertSame($expectedFile, $resolved);

        $loader = static function (string $name) use ($library, $ns): void {
            if (!str_starts_with($name, $ns)) {
                return;
            }
            $rel = substr($name, strlen($ns));
            if (str_contains($rel, '\\')) {
                return;
            }
            $file = $library . '/' . strtolower(preg_replace('~([a-z])([A-Z]|[0-9])~', '\1_\2', $rel)) . '.php';
            if (is_file($file)) {
                include_once $file;
            }
        };
        spl_autoload_register($loader);
        try {
            self::assertTrue(class_exists(SatrudnikFailureNotifier::class, true));
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function testCpFailureSendsWhenRecipientValidAndPreviousDiffers(): void
    {
        $sent = [];
        $notifier = $this->recordingNotifier($sent);
        $status = BankStatus::cpFailure();

        $ok = $notifier->notifyIfEligible(
            ['satrudnik_email' => 'satrudnik@example.com'],
            920,
            $status['status_id'],
            $status['status_label'],
            null,
            null,
            '2026-09-18 10:00:00'
        );

        self::assertTrue($ok);
        self::assertCount(1, $sent);
        self::assertSame('satrudnik@example.com', $sent[0]['to']);
        self::assertSame(
            'Проблем при изпращане на заявка за финансиране - поръчка 920',
            $sent[0]['subject']
        );
        self::assertStringContainsString('Магазин поръчка: 920', $sent[0]['body']);
        self::assertStringContainsString('Дата на поръчката: 2026-09-18 10:00:00', $sent[0]['body']);
        self::assertStringNotContainsString('КП поръчка:', $sent[0]['body']);
        self::assertStringContainsString(
            'Статус: Неуспешно изпратен Банка - КП (bank_send_failed_cp)',
            $sent[0]['body']
        );
        self::assertStringNotContainsString('ЕГН', $sent[0]['body']);
        self::assertStringNotContainsString('correlation', $sent[0]['body']);
    }

    public function testSmartUcfFailureIncludesCpOrderId(): void
    {
        $sent = [];
        $notifier = $this->recordingNotifier($sent);
        $status = BankStatus::smartUcfFailure();

        $ok = $notifier->notifyIfEligible(
            ['satrudnik_email' => 'satrudnik@example.com'],
            821,
            $status['status_id'],
            $status['status_label'],
            'cp_sent',
            906,
            '2026-09-17 12:00:00'
        );

        self::assertTrue($ok);
        self::assertCount(1, $sent);
        self::assertStringContainsString('КП поръчка: 906', $sent[0]['body']);
        self::assertStringContainsString(
            'Статус: Неуспешно изпратен Банка - SmartUCF (bank_send_failed_smartucf)',
            $sent[0]['body']
        );
    }

    public function testMissingOrInvalidRecipientSkipsSilently(): void
    {
        $sent = [];
        $notifier = $this->recordingNotifier($sent);
        $status = BankStatus::cpFailure();

        foreach (
            [
                [],
                ['satrudnik_email' => null],
                ['satrudnik_email' => ''],
                ['satrudnik_email' => '   '],
                ['satrudnik_email' => 'not-an-email'],
            ] as $shop
        ) {
            self::assertFalse($notifier->notifyIfEligible(
                $shop,
                10,
                $status['status_id'],
                $status['status_label'],
                null
            ));
        }

        self::assertSame([], $sent);
    }

    public function testNonTargetStatusesDoNotSend(): void
    {
        $sent = [];
        $notifier = $this->recordingNotifier($sent);
        $shop = ['satrudnik_email' => 'satrudnik@example.com'];

        foreach (
            [
                [BankStatus::SENT_PROCESS1, BankStatus::LABEL_SENT_PROCESS1],
                [BankStatus::SENT_PROCESS2, BankStatus::LABEL_SENT_PROCESS2],
                ['bank_send_failed', 'Неуспешно изпратен Банка'],
                ['outcome_unknown', 'outcome_unknown'],
            ] as [$statusId, $label]
        ) {
            self::assertFalse($notifier->notifyIfEligible($shop, 11, $statusId, $label, null));
        }

        self::assertSame([], $sent);
    }

    public function testDuplicateGuardSuppressesSameTargetReplay(): void
    {
        $sent = [];
        $notifier = $this->recordingNotifier($sent);
        $status = BankStatus::cpFailure();
        $shop = ['satrudnik_email' => 'satrudnik@example.com'];

        self::assertTrue($notifier->notifyIfEligible(
            $shop,
            55,
            $status['status_id'],
            $status['status_label'],
            null
        ));
        self::assertFalse($notifier->notifyIfEligible(
            $shop,
            55,
            $status['status_id'],
            $status['status_label'],
            BankStatus::SEND_FAILED_CP
        ));
        self::assertCount(1, $sent);

        $sent = [];
        $notifier = $this->recordingNotifier($sent);
        $smart = BankStatus::smartUcfFailure();
        self::assertTrue($notifier->notifyIfEligible(
            $shop,
            56,
            $smart['status_id'],
            $smart['status_label'],
            null,
            100
        ));
        self::assertFalse($notifier->notifyIfEligible(
            $shop,
            56,
            $smart['status_id'],
            $smart['status_label'],
            BankStatus::SEND_FAILED_SMARTUCF,
            100
        ));
        self::assertCount(1, $sent);
    }

    public function testCpThenSmartUcfTransitionStillEligible(): void
    {
        $sent = [];
        $notifier = $this->recordingNotifier($sent);
        $shop = ['satrudnik_email' => 'satrudnik@example.com'];
        $smart = BankStatus::smartUcfFailure();

        self::assertTrue($notifier->notifyIfEligible(
            $shop,
            57,
            $smart['status_id'],
            $smart['status_label'],
            BankStatus::SEND_FAILED_CP,
            200
        ));
        self::assertCount(1, $sent);
    }

    public function testNoFallbackToUniEmailOrStoreAdmin(): void
    {
        $sent = [];
        $notifier = $this->recordingNotifier($sent);
        $status = BankStatus::cpFailure();

        self::assertFalse($notifier->notifyIfEligible(
            [
                'uni_email' => 'admin@shop.example',
                'store_email' => 'store@shop.example',
                'config_email' => 'config@shop.example',
            ],
            60,
            $status['status_id'],
            $status['status_label'],
            null
        ));
        self::assertSame([], $sent);
    }

    public function testMailTransportFailureIsIsolated(): void
    {
        $notifier = new SatrudnikFailureNotifier(
            static function () {
                throw new \RuntimeException('smtp down');
            }
        );
        $status = BankStatus::cpFailure();

        self::assertFalse($notifier->notifyIfEligible(
            ['satrudnik_email' => 'satrudnik@example.com'],
            70,
            $status['status_id'],
            $status['status_label'],
            null
        ));
    }

    public function testContractCallSitesWireSharedLifecyclePaths(): void
    {
        $completion = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/financing_control_panel_completion.php'
        );
        self::assertStringContainsString('findCurrentStatus', $completion);
        self::assertStringContainsString('upsertAuthorizedLocal', $completion);
        self::assertStringContainsString('SatrudnikFailureNotifier', $completion);
        self::assertStringContainsString('BankStatus::cpFailure()', $completion);

        $upsertPos = strpos($completion, 'upsertAuthorizedLocal');
        $notifyPos = strpos($completion, 'notifyIfEligible');
        self::assertNotFalse($upsertPos);
        self::assertNotFalse($notifyPos);
        self::assertGreaterThan($upsertPos, $notifyPos);

        $post = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/post_control_panel_lifecycle_service.php'
        );
        self::assertStringContainsString('readPreviousBankStatusId', $post);
        self::assertStringContainsString('SatrudnikFailureNotifier', $post);
        self::assertStringContainsString('notifyIfEligible', $post);
        self::assertStringContainsString('BankStatus::smartUcfFailure()', $post);
        self::assertStringContainsString('STEP_SMARTUCF_TERMINAL_FAILED', $post);

        $product = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/product_financing_submission_service.php'
        );
        $cart = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/cart_financing_submission_service.php'
        );
        $checkout = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/checkout_financing_submission_service.php'
        );
        self::assertStringContainsString('FinancingControlPanelCompletion::apply', $product);
        self::assertStringContainsString('FinancingControlPanelCompletion::apply', $cart);
        self::assertStringContainsString('FinancingControlPanelCompletion::apply', $checkout);
    }

    public function testTransportUsesInjectedMailerNotPhpMailHeaders(): void
    {
        $sent = [];
        $notifier = $this->recordingNotifier($sent);
        $status = BankStatus::cpFailure();
        $notifier->notifyIfEligible(
            ['satrudnik_email' => 'satrudnik@example.com'],
            1,
            $status['status_id'],
            $status['status_label'],
            null
        );
        self::assertCount(1, $sent);
        // Plain subject — OpenCart Mail SMTP adaptor encodes; injectable path receives plain text.
        self::assertStringNotContainsString('=?UTF-8?B?', $sent[0]['subject']);
        self::assertSame('', $sent[0]['headers']);
    }

    /**
     * @param list<array{to: string, subject: string, body: string, headers: string}> $sent
     */
    private function recordingNotifier(array &$sent): SatrudnikFailureNotifier
    {
        return new SatrudnikFailureNotifier(
            static function (string $to, string $subject, string $body, string $headers) use (&$sent): bool {
                $sent[] = compact('to', 'subject', 'body', 'headers');

                return true;
            }
        );
    }
}
