<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\BankStatus;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingLeasingPresenter;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationAudience;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationSnapshot;
use Opencart\System\Library\Extension\MtUniCredit\FinancingTerminalNavigationSupport;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\PostControlPanelLifecycleService;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingResult;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfFailureClassification;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfFailureClassifier;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleRepository;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleStates;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfSessionCoordinator;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfSessionException;
use Opencart\System\Library\Extension\MtUniCredit\OrderBankStatusRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

/**
 * Phase 11C Remediation 04 — SmartUCF definite failure → terminal Thank You (PS9 checkout / Woo parity).
 */
final class Phase11CSmartUcfTerminalFailureTest extends TestCase
{
    public function testDefiniteRemoteRejectReturnsTerminalThankYouResult(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration database is unavailable.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        (new \Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller($db))->installAll();
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempts = new FinancingAttemptRepository($db);
        $row = $attempts->issueWithSubmissionToken(
            $submission->storeId,
            $submission->entryPoint,
            hash('sha256', 'terminal-reject-op'),
            hash('sha256', 'terminal-reject-actor'),
            hash('sha256', 'terminal-reject-selection')
        );
        $attemptId = (int) $row['attempt_id'];
        $attempts->attachOrder($attemptId, 821);

        $coordinator = new SmartUcfSessionCoordinator(
            new SmartUcfLifecycleRepository($db),
            new class {
                public function createSession(): array
                {
                    throw new SmartUcfSessionException(
                        'Rejected',
                        false,
                        '{"error":"rejected"}',
                        422,
                        SmartUcfSessionException::KIND_REMOTE
                    );
                }
            },
            new SmartUcfFailureClassifier(),
            new OrderBankStatusRepository($db),
            \MtUniCredit\Tests\Support\Phase4TestHarness::services(
                new \MtUniCredit\Tests\Support\FakeCpHttpTransport(),
                null,
                $db,
                $submission->storeId
            )['client']
        );
        $service = new PostControlPanelLifecycleService($coordinator, null, 'https://shop.test/success');
        $result = $service->handle($attemptId, $submission, 821, 906, mt_uni_credit_valid_shop_snapshot());

        self::assertFalse($result->success);
        self::assertSame(FinancingTerminalNavigationSupport::STEP_SMARTUCF_TERMINAL_FAILED, $result->step);
        self::assertSame(BankStatus::SEND_FAILED_SMARTUCF, $result->errorCode);
        self::assertFalse($result->bankSubmitted);
        self::assertSame('https://shop.test/success', $result->redirectUrl);

        $payload = $result->toArray();
        self::assertTrue($payload['terminal']);
        self::assertTrue($payload['bank_failure_known']);
        self::assertSame(FinancingLeasingPresenter::SMARTUCF_TERMINAL_FAILURE_MESSAGE, $payload['message']);
    }

    public function testRetryablePreSendStillThrowsInteractiveError(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration database is unavailable.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        (new \Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller($db))->installAll();
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempts = new FinancingAttemptRepository($db);
        $row = $attempts->issueWithSubmissionToken(
            $submission->storeId,
            $submission->entryPoint,
            hash('sha256', 'terminal-pre-send-op'),
            hash('sha256', 'terminal-pre-send-actor'),
            hash('sha256', 'terminal-pre-send-selection')
        );
        $attemptId = (int) $row['attempt_id'];
        $attempts->attachOrder($attemptId, 822);

        $coordinator = new SmartUcfSessionCoordinator(
            new SmartUcfLifecycleRepository($db),
            new class {
                public function createSession(): array
                {
                    throw new SmartUcfSessionException(
                        'Pre-send',
                        true,
                        '',
                        0,
                        SmartUcfSessionException::KIND_PRE_SEND
                    );
                }
            },
            new SmartUcfFailureClassifier(),
            new OrderBankStatusRepository($db),
            \MtUniCredit\Tests\Support\Phase4TestHarness::services(
                new \MtUniCredit\Tests\Support\FakeCpHttpTransport(),
                null,
                $db,
                $submission->storeId
            )['client']
        );
        $service = new PostControlPanelLifecycleService($coordinator);

        $this->expectException(ProductFinancingFlowException::class);
        try {
            $service->handle($attemptId, $submission, 822, 907, mt_uni_credit_valid_shop_snapshot());
        } catch (ProductFinancingFlowException $exception) {
            self::assertSame('smartucf_submit_failed', $exception->errorCode());
            throw $exception;
        }
    }

    public function testOutcomeUnknownDoesNotReturnTerminalThankYou(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration database is unavailable.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        (new \Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller($db))->installAll();
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempts = new FinancingAttemptRepository($db);
        $row = $attempts->issueWithSubmissionToken(
            $submission->storeId,
            $submission->entryPoint,
            hash('sha256', 'terminal-unknown-op'),
            hash('sha256', 'terminal-unknown-actor'),
            hash('sha256', 'terminal-unknown-selection')
        );
        $attemptId = (int) $row['attempt_id'];
        $attempts->attachOrder($attemptId, 823);

        $coordinator = new SmartUcfSessionCoordinator(
            new SmartUcfLifecycleRepository($db),
            new class {
                public function createSession(): array
                {
                    throw new SmartUcfSessionException(
                        'Timeout',
                        false,
                        '',
                        0,
                        SmartUcfSessionException::KIND_TRANSPORT
                    );
                }
            },
            new SmartUcfFailureClassifier(),
            new OrderBankStatusRepository($db),
            \MtUniCredit\Tests\Support\Phase4TestHarness::services(
                new \MtUniCredit\Tests\Support\FakeCpHttpTransport(),
                null,
                $db,
                $submission->storeId
            )['client']
        );
        $service = new PostControlPanelLifecycleService($coordinator);
        $result = $service->handle($attemptId, $submission, 823, 908, mt_uni_credit_valid_shop_snapshot());

        self::assertSame('bank_outcome_unknown', $result->step);
        self::assertSame('smartucf_outcome_unknown', $result->errorCode);
        self::assertArrayNotHasKey('terminal', $result->toArray());
    }

    public function testTerminalNavigationEnrichesSessionAndRedirectUrl(): void
    {
        $result = new ProductFinancingResult(
            false,
            FinancingTerminalNavigationSupport::STEP_SMARTUCF_TERMINAL_FAILED,
            9001,
            FinancingLeasingPresenter::SMARTUCF_TERMINAL_FAILURE_MESSAGE,
            false,
            SmartUcfLifecycleStates::FAILED,
            701,
            BankStatus::SEND_FAILED_SMARTUCF,
            '',
            false
        );
        $session = [];
        $payload = FinancingTerminalNavigationSupport::enrichTerminalPayload(
            $result->toArray(),
            $result,
            'https://shop.test/index.php?route=checkout/success',
            $session
        );
        self::assertSame(9001, $session['order_id']);
        self::assertSame(9001, $session['mt_uni_credit_success_order_id']);
        self::assertSame('https://shop.test/index.php?route=checkout/success', $payload['redirect_url']);
    }

    public function testThankYouPresenterShowsSmartUcfFailureMessageWithoutPii(): void
    {
        $presenter = new FinancingLeasingPresenter();
        $snapshot = new FinancingPresentationSnapshot(
            9001,
            701,
            false,
            12,
            'KOPSTD',
            0.0,
            1000.0,
            88.0,
            1056.0,
            5.0,
            6.0
        );
        $rows = $presenter->rows(
            $snapshot,
            BankStatus::LABEL_SEND_FAILED_SMARTUCF,
            FinancingPresentationAudience::CUSTOMER
        );
        $labels = array_column($rows, 'label');
        self::assertContains(FinancingLeasingPresenter::LABEL_BANK_STATUS, $labels);
        self::assertContains(FinancingLeasingPresenter::LABEL_MESSAGE, $labels);
        self::assertNotContains(FinancingLeasingPresenter::LABEL_EGN, $labels);
        self::assertNotContains(FinancingLeasingPresenter::LABEL_PHONE2, $labels);

        $messageRow = null;
        foreach ($rows as $row) {
            if ($row['label'] === FinancingLeasingPresenter::LABEL_MESSAGE) {
                $messageRow = $row;
                break;
            }
        }
        self::assertNotNull($messageRow);
        self::assertSame(FinancingLeasingPresenter::SMARTUCF_TERMINAL_FAILURE_MESSAGE, $messageRow['value']);
        self::assertStringContainsString('Не изпращайте поръчката повторно', $messageRow['value']);
    }

    public function testControllersAndJsWireTerminalThankYouNavigation(): void
    {
        $root = dirname(__DIR__);
        foreach (
            [
                'catalog/controller/module/mt_uni_credit_product.php',
                'catalog/controller/module/mt_uni_credit_cart.php',
                'catalog/controller/payment/mt_uni_credit.php',
            ] as $file
        ) {
            $src = (string) file_get_contents($root . '/' . $file);
            self::assertStringContainsString('FinancingTerminalNavigationSupport::enrichTerminalPayload', $src, $file);
        }
        self::assertStringContainsString(
            'isSmartUcfTerminalFailure',
            (string) file_get_contents($root . '/catalog/controller/payment/mt_uni_credit.php')
        );

        $redirect = (string) file_get_contents($root . '/catalog/view/javascript/mt_uni_credit_redirect.js');
        self::assertStringContainsString('navigateTerminalThankYou', $redirect);
        self::assertStringContainsString('checkout/success', $redirect);

        foreach (['mt_uni_credit_product.js', 'mt_uni_credit_cart.js'] as $file) {
            $js = (string) file_get_contents($root . '/catalog/view/javascript/' . $file);
            self::assertStringContainsString('navigateTerminalThankYou', $js, $file);
            self::assertMatchesRegularExpression(
                '/navigateTerminalThankYou[\s\S]*?redirectTerminal\s*=\s*true[\s\S]*?return;/',
                $js
            );
        }
        $checkoutJs = (string) file_get_contents($root . '/catalog/view/javascript/mt_uni_credit_checkout.js');
        self::assertStringContainsString('navigateCheckoutTerminalThankYou', $checkoutJs);
        self::assertStringContainsString('navigateTerminalThankYou', $checkoutJs);
        self::assertMatchesRegularExpression(
            '/navigateCheckoutTerminalThankYou\(json\)[\s\S]*?redirectTerminal\s*=\s*true[\s\S]*?return;/',
            $checkoutJs
        );
    }

    public function testKnownFailureReplayDoesNotCallSmartUcfAgain(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration database is unavailable.');
        }
        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        (new \Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller($db))->installAll();
        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempts = new FinancingAttemptRepository($db);
        $row = $attempts->issueWithSubmissionToken(
            $submission->storeId,
            $submission->entryPoint,
            hash('sha256', 'terminal-replay-op'),
            hash('sha256', 'terminal-replay-actor'),
            hash('sha256', 'terminal-replay-selection')
        );
        $attemptId = (int) $row['attempt_id'];
        $attempts->attachOrder($attemptId, 820);
        $lifecycle = new SmartUcfLifecycleRepository($db);
        $lifecycle->markFailed($attemptId, SmartUcfFailureClassification::CLASS_REMOTE_REJECT, false);

        $calls = 0;
        $coordinator = new SmartUcfSessionCoordinator(
            $lifecycle,
            new class($calls) {
                public function __construct(private int &$calls) {}

                public function createSession(): array
                {
                    $this->calls++;
                    throw new \RuntimeException('SmartUCF must not be called on terminal replay');
                }
            },
            new SmartUcfFailureClassifier(),
            new OrderBankStatusRepository($db),
            Phase4TestHarness::services(new \MtUniCredit\Tests\Support\FakeCpHttpTransport(), null, $db, $submission->storeId)['client']
        );
        $service = new PostControlPanelLifecycleService($coordinator, null, 'https://shop.test/success');
        $result = $service->handle($attemptId, $submission, 820, 905, mt_uni_credit_valid_shop_snapshot(), true);

        self::assertSame(0, $calls);
        self::assertSame(FinancingTerminalNavigationSupport::STEP_SMARTUCF_TERMINAL_FAILED, $result->step);
        self::assertSame('https://shop.test/success', $result->redirectUrl);
    }

    public function testModuleVersionRemains202(): void
    {
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }
}
