<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\BankStatus;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingLeasingPresenter;
use Opencart\System\Library\Extension\MtUniCredit\FinancingTerminalNavigationSupport;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\OrderBankStatusRepository;
use Opencart\System\Library\Extension\MtUniCredit\PostControlPanelLifecycleService;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfFailureClassification;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfFailureClassifier;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleRepository;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleStates;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfSessionCoordinator;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfSessionException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

/**
 * Phase 11C Remediation 07 — structured SmartUCF business reject (errorCode) → local/CP failure + Thank You.
 */
final class Phase11CSmartUcfBusinessRejectTest extends TestCase
{
    private const FIXTURE_121 = '{"errorCode":121,"errorText":"Вече е регистрирана поръчка със същия номер","sucfOnlineSessionID":null}';

    private const FIXTURE_GENERIC = '{"errorCode":42,"errorText":"Схемата е отказана от банката","sucfOnlineSessionID":null}';

    public function testErrorCode121IsDefiniteRemoteRejectNotOutcomeUnknown(): void
    {
        $classifier = new SmartUcfFailureClassifier();
        $exception = new SmartUcfSessionException(
            'SmartUCF did not return a session identifier.',
            false,
            self::FIXTURE_121,
            200,
            SmartUcfSessionException::KIND_DUPLICATE // even if client mis-tagged duplicate
        );
        $classification = $classifier->classifyThrowable($exception);

        self::assertSame(SmartUcfLifecycleStates::FAILED, $classification->targetState());
        self::assertSame(SmartUcfFailureClassification::CLASS_REMOTE_REJECT, $classification->errorClass());
        self::assertFalse($classification->isRetryable());
        self::assertNotSame(SmartUcfFailureClassification::CLASS_DUPLICATE_ORDER_NO, $classification->errorClass());
    }

    public function testGenericBusinessErrorCodeIsDefiniteReject(): void
    {
        $classifier = new SmartUcfFailureClassifier();
        $exception = new SmartUcfSessionException(
            'SmartUCF did not return a session identifier.',
            false,
            self::FIXTURE_GENERIC,
            200,
            SmartUcfSessionException::KIND_REMOTE
        );
        $classification = $classifier->classifyThrowable($exception);

        self::assertSame(SmartUcfFailureClassification::CLASS_REMOTE_REJECT, $classification->errorClass());
        self::assertSame(SmartUcfLifecycleStates::FAILED, $classification->targetState());
    }

    public function testHttp200BusinessRejectWritesLocalAndCpFailureAndTerminalThankYou(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration database is unavailable.');
        }

        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        (new \Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller($db))->installAll();

        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempts = new FinancingAttemptRepository($db);
        $orderId = 9801;
        $row = $attempts->issueWithSubmissionToken(
            $submission->storeId,
            $submission->entryPoint,
            hash('sha256', 'biz-reject-121-op'),
            hash('sha256', 'biz-reject-121-actor'),
            hash('sha256', 'biz-reject-121-sel')
        );
        $attemptId = (int) $row['attempt_id'];
        $attempts->attachOrder($attemptId, $orderId);

        $transport = new \MtUniCredit\Tests\Support\FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate();
        $services = Phase4TestHarness::services($transport, null, $db, $submission->storeId);

        $fixture121 = self::FIXTURE_121;
        $coordinator = new SmartUcfSessionCoordinator(
            new SmartUcfLifecycleRepository($db),
            new class($fixture121) {
                public function __construct(private string $rawResponse) {}

                public function createSession(): array
                {
                    throw new SmartUcfSessionException(
                        'SmartUCF did not return a session identifier.',
                        false,
                        $this->rawResponse,
                        200,
                        SmartUcfSessionException::KIND_DUPLICATE
                    );
                }
            },
            new SmartUcfFailureClassifier(),
            new OrderBankStatusRepository($db),
            $services['client']
        );

        $thankYou = 'https://shop.test/index.php?route=checkout/success';
        $service = new PostControlPanelLifecycleService($coordinator, null, $thankYou);
        $result = $service->handle($attemptId, $submission, $orderId, 99001, mt_uni_credit_valid_shop_snapshot());

        self::assertFalse($result->success);
        self::assertSame(FinancingTerminalNavigationSupport::STEP_SMARTUCF_TERMINAL_FAILED, $result->step);
        self::assertSame(BankStatus::SEND_FAILED_SMARTUCF, $result->errorCode);
        self::assertSame($thankYou, $result->redirectUrl);
        self::assertFalse($result->bankSubmitted);
        self::assertSame(FinancingLeasingPresenter::SMARTUCF_TERMINAL_FAILURE_MESSAGE, $result->message);

        $status = $db->query(
            'SELECT `status_id`, `status_label` FROM `' . $db->getPrefix()
                . 'mt_uni_credit_order_bank_status` WHERE `order_id` = ' . $orderId
        );
        self::assertSame(BankStatus::SEND_FAILED_SMARTUCF, $status->row['status_id']);
        self::assertSame(BankStatus::LABEL_SEND_FAILED_SMARTUCF, $status->row['status_label']);
        self::assertNotSame(BankStatus::SENT_PROCESS1, $status->row['status_id']);

        $patches = array_values(array_filter(
            $transport->requests,
            static fn(array $request): bool => $request['method'] === 'PATCH'
                && str_contains($request['url'], '/orders/status')
        ));
        self::assertNotEmpty($patches);
        $last = $patches[array_key_last($patches)];
        self::assertSame(BankStatus::SEND_FAILED_SMARTUCF, $last['payload']['status_id']);
        self::assertSame((string) $orderId, $last['payload']['order_id']);

        $lifecycle = (new SmartUcfLifecycleRepository($db))->findByAttempt($attemptId);
        self::assertSame(SmartUcfLifecycleStates::FAILED, $lifecycle['smartucf_state']);
        self::assertSame(SmartUcfFailureClassification::CLASS_REMOTE_REJECT, $lifecycle['smartucf_error_class']);
    }

    public function testValidSuccessStillWritesProcess1AndBankRedirect(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration database is unavailable.');
        }

        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        (new \Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller($db))->installAll();

        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempts = new FinancingAttemptRepository($db);
        $orderId = 9802;
        $row = $attempts->issueWithSubmissionToken(
            $submission->storeId,
            $submission->entryPoint,
            hash('sha256', 'biz-success-op'),
            hash('sha256', 'biz-success-actor'),
            hash('sha256', 'biz-success-sel')
        );
        $attemptId = (int) $row['attempt_id'];
        $attempts->attachOrder($attemptId, $orderId);

        $transport = new \MtUniCredit\Tests\Support\FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate();
        $services = Phase4TestHarness::services($transport, null, $db, $submission->storeId);

        $sessionId = 'session-biz-success';
        $bankUrl = 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/' . $sessionId;
        $coordinator = new SmartUcfSessionCoordinator(
            new SmartUcfLifecycleRepository($db),
            new class($sessionId, $bankUrl) {
                public function __construct(private string $sessionId, private string $bankUrl) {}

                /** @return array<string, mixed> */
                public function createSession(): array
                {
                    return [
                        'session_id' => $this->sessionId,
                        'redirect_url' => $this->bankUrl,
                        'http_code' => 200,
                        'raw_request' => '{}',
                        'raw_response' => json_encode([
                            'errorCode' => null,
                            'errorText' => null,
                            'sucfOnlineSessionID' => $this->sessionId,
                        ], JSON_THROW_ON_ERROR),
                        'endpoint' => 'https://online.ucfin.bg/suos/api/otp/sucfOnlineSessionStart',
                    ];
                }
            },
            new SmartUcfFailureClassifier(),
            new OrderBankStatusRepository($db),
            $services['client']
        );

        $result = (new PostControlPanelLifecycleService($coordinator))->handle(
            $attemptId,
            $submission,
            $orderId,
            99002,
            mt_uni_credit_valid_shop_snapshot()
        );

        self::assertTrue($result->success);
        self::assertSame('bank_redirect', $result->step);
        self::assertSame($bankUrl, $result->redirectUrl);

        $status = $db->query(
            'SELECT `status_id` FROM `' . $db->getPrefix() . 'mt_uni_credit_order_bank_status` WHERE `order_id` = ' . $orderId
        );
        self::assertSame(BankStatus::SENT_PROCESS1, $status->row['status_id']);
    }

    public function testAmbiguousDuplicateWithoutErrorCodeRemainsOutcomeUnknown(): void
    {
        $classifier = new SmartUcfFailureClassifier();
        $exception = new SmartUcfSessionException(
            'SmartUCF did not return a session identifier.',
            false,
            'duplicate order already exists',
            200,
            SmartUcfSessionException::KIND_DUPLICATE
        );
        $classification = $classifier->classifyThrowable($exception);

        self::assertSame(SmartUcfLifecycleStates::OUTCOME_UNKNOWN, $classification->targetState());
        self::assertSame(SmartUcfFailureClassification::CLASS_DUPLICATE_ORDER_NO, $classification->errorClass());
    }

    public function testCheckoutJsTerminalRejectBeforeInteractiveError(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js');
        self::assertMatchesRegularExpression(
            '/navigateTerminalThankYou\(json\.redirect_url\)[\s\S]*?redirectTerminal\s*=\s*true[\s\S]*?return;/',
            $js
        );
        $thankYouPos = strpos($js, 'navigateTerminalThankYou(json.redirect_url)');
        $errorPos = strpos($js, 'Заявката не може да бъде обработена.');
        self::assertNotFalse($thankYouPos);
        self::assertNotFalse($errorPos);
        self::assertLessThan($errorPos, $thankYouPos);
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    public function testCheckoutControllerDoesNotAddHistoryForNonSuccess(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php');
        self::assertStringContainsString('if (!$result->success)', $src);
        self::assertMatchesRegularExpression(
            '/isSmartUcfTerminalFailure\(\$result\)[\s\S]*?return \$payload;[\s\S]*?if\s*\(\s*!\$result->success\s*\)/',
            $src
        );
    }
}
