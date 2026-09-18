<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\FakeCpHttpTransport;
use MtUniCredit\Tests\Support\InMemoryCheckoutOrderAdapter;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use MtUniCredit\Tests\Support\ProductFinancingTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\BankStatus;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelClient;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelErrorClass;
use Opencart\System\Library\Extension\MtUniCredit\CpHttpException;
use Opencart\System\Library\Extension\MtUniCredit\CpMalformedJsonException;
use Opencart\System\Library\Extension\MtUniCredit\CpTokenRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptState;
use Opencart\System\Library\Extension\MtUniCredit\FinancingLeasingPresenter;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationAudience;
use Opencart\System\Library\Extension\MtUniCredit\FinancingPresentationSnapshot;
use Opencart\System\Library\Extension\MtUniCredit\FinancingTerminalNavigationSupport;
use Opencart\System\Library\Extension\MtUniCredit\LockOwnerTokenGenerator;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ModuleCredentialsRepository;
use Opencart\System\Library\Extension\MtUniCredit\OrderBankStatusRepository;
use Opencart\System\Library\Extension\MtUniCredit\PersistenceClock;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingResult;
use Opencart\System\Library\Extension\MtUniCredit\ProductOperationIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductSubmissionIssuer;
use PHPUnit\Framework\TestCase;

/**
 * Definitive CP create failure → bank_send_failed_cp + success redirect + standard emails.
 */
final class DefinitiveCpCreateFailureRemediationTest extends TestCase
{
    private FinancingAttemptRepository $attempts;

    protected function setUp(): void
    {
        if (str_contains($this->name(), 'Unit') || str_contains($this->name(), 'Contract')) {
            return;
        }
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Set MT_UNI_CREDIT_INTEGRATION=1 for DB integration tests.');
        }
        try {
            PersistenceIntegrationHarness::resetTables();
            $this->attempts = new FinancingAttemptRepository(PersistenceIntegrationHarness::connection());
        } catch (\Throwable $exception) {
            self::markTestSkipped('Integration database unavailable: ' . $exception->getMessage());
        }
    }

    public function testUnitBankStatusVocabulary(): void
    {
        $status = BankStatus::cpFailure();
        self::assertSame('bank_send_failed_cp', $status['status_id']);
        self::assertSame('Неуспешно изпратен Банка - КП', $status['status_label']);
        self::assertSame('2.0.3', ModuleConstants::VERSION);
    }

    public function testUnitEmailLeasingContainsExactCpFailureLabel(): void
    {
        $presenter = new FinancingLeasingPresenter();
        $snapshot = new FinancingPresentationSnapshot(55, null, false, 6, 'KOP', 0, 100, 20, 120, 10, 12);
        $rows = $presenter->rows(
            $snapshot,
            BankStatus::LABEL_SEND_FAILED_CP,
            FinancingPresentationAudience::CUSTOMER
        );
        $map = array_column($rows, 'value', 'label');
        self::assertSame(BankStatus::LABEL_SEND_FAILED_CP, $map[FinancingLeasingPresenter::LABEL_BANK_STATUS]);
        self::assertSame(
            FinancingLeasingPresenter::CP_TERMINAL_FAILURE_MESSAGE,
            $map[FinancingLeasingPresenter::LABEL_MESSAGE]
        );
        $text = $presenter->renderText($rows);
        self::assertStringContainsString("Статус към банката: Неуспешно изпратен Банка - КП", $text);
        self::assertStringNotContainsString('HTTP', $text);
        self::assertStringNotContainsString('outcome_unknown', $text);
    }

    public function testUnitThankYouTerminalIncludesCpFailure(): void
    {
        $result = new ProductFinancingResult(
            false,
            FinancingTerminalNavigationSupport::STEP_CP_TERMINAL_FAILED,
            42,
            FinancingLeasingPresenter::CP_TERMINAL_FAILURE_MESSAGE,
            false,
            FinancingAttemptState::TERMINAL_FAILED,
            null,
            BankStatus::SEND_FAILED_CP,
            '',
            false
        );
        self::assertTrue(FinancingTerminalNavigationSupport::isCpTerminalFailure($result));
        self::assertTrue(FinancingTerminalNavigationSupport::isThankYouTerminalStep($result));
        $payload = $result->toArray();
        self::assertTrue($payload['terminal']);
        self::assertTrue($payload['bank_failure_known']);
        self::assertSame(BankStatus::SEND_FAILED_CP, $payload['error_code']);

        $session = [];
        $enriched = FinancingTerminalNavigationSupport::enrichTerminalPayload(
            $payload,
            $result,
            'https://shop.example/index.php?route=checkout/success',
            $session
        );
        self::assertSame('https://shop.example/index.php?route=checkout/success', $enriched['redirect_url']);
        self::assertSame(42, $session['mt_uni_credit_success_order_id']);
    }

    public function testContractSourceWiresCentralDefinitivePath(): void
    {
        $completion = (string) file_get_contents(dirname(__DIR__) . '/system/library/financing_control_panel_completion.php');
        self::assertStringContainsString('definitiveFailure', $completion);
        self::assertStringContainsString('BankStatus::cpFailure()', $completion);
        self::assertStringContainsString('STEP_CP_TERMINAL_FAILED', $completion);

        $lifecycle = (string) file_get_contents(dirname(__DIR__) . '/system/library/control_panel_order_lifecycle_service.php');
        self::assertStringContainsString('DEFINITIVE_ENDPOINT_REJECTION_STATUSES', $lifecycle);
        self::assertStringContainsString('403, 404, 405, 410', $lifecycle);

        $checkoutJs = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js');
        self::assertStringContainsString('cp_terminal_failed', $checkoutJs);

        $payment = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php');
        self::assertStringContainsString('isCpTerminalFailure', $payment);
    }

    public function testContractOcAutoloadStubsExistForCpExceptions(): void
    {
        $library = dirname(__DIR__) . '/system/library';
        foreach ([
            'cp_http_exception.php',
            'cp_malformed_json_exception.php',
            'cp_invalid_payload_exception.php',
            'cp_authentication_exception.php',
            'cp_connection_exception.php',
            'cp_timeout_exception.php',
        ] as $stub) {
            $path = $library . '/' . $stub;
            self::assertFileExists($path, $stub . ' required for OpenCart snake_case autoload');
            $contents = (string) file_get_contents($path);
            self::assertStringContainsString("require_once __DIR__ . '/cp_exception.php';", $contents);
        }

        // OpenCart Autoloader must resolve sibling exception classes without a prior CpException load.
        $dir = $library . '/';
        $ns = 'Opencart\\System\\Library\\Extension\\MtUniCredit\\';
        $loader = static function (string $class) use ($dir, $ns): void {
            if (!str_starts_with($class, $ns)) {
                return;
            }
            $relative = substr($class, strlen($ns));
            if (str_contains($relative, '\\')) {
                return;
            }
            $file = $dir . strtolower(preg_replace('~([a-z])([A-Z]|[0-9])~', '\1_\2', $relative)) . '.php';
            if (is_file($file)) {
                include_once $file;
            }
        };
        spl_autoload_register($loader);
        try {
            self::assertTrue(class_exists(CpMalformedJsonException::class, true));
            self::assertTrue(class_exists(CpHttpException::class, true));
            $exception = new CpMalformedJsonException('autoload-ok');
            self::assertSame('autoload-ok', $exception->getMessage());
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function testProcess1DefinitiveEndpointRejectionPersistsCpFailureAndRedirects(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        // Bare 404 proves create endpoint rejected the request — no CP order.
        $transport->enqueue(404, 'Not Found');
        $orders = new InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport);

        $result = $this->submitProduct($service, false);
        self::assertFalse($result->success);
        self::assertSame(FinancingTerminalNavigationSupport::STEP_CP_TERMINAL_FAILED, $result->step);
        self::assertSame(BankStatus::SEND_FAILED_CP, $result->errorCode);
        self::assertSame(FinancingLeasingPresenter::CP_TERMINAL_FAILURE_MESSAGE, $result->message);

        $orderId = (int) $result->orderId;
        self::assertGreaterThan(0, $orderId);
        self::assertSame(1, $orders->historyCountFor($orderId));

        $db = PersistenceIntegrationHarness::connection();
        $bank = (new OrderBankStatusRepository($db))->findCurrentStatus(ProductFinancingTestHarness::STORE_ID, $orderId);
        self::assertNotNull($bank);
        self::assertSame(BankStatus::SEND_FAILED_CP, $bank['status_id']);
        self::assertSame(BankStatus::LABEL_SEND_FAILED_CP, $bank['status_label']);

        $row = $this->attempts->findByOrderId(ProductFinancingTestHarness::STORE_ID, $orderId);
        self::assertSame(FinancingAttemptState::TERMINAL_FAILED, $row['state']);
        self::assertNull($row['control_panel_order_id']);
        self::assertSame(1, $transport->countOrderCreates());
        self::assertSame(0, $transport->countStatusPatches());

        $session = [];
        $payload = FinancingTerminalNavigationSupport::enrichTerminalPayload(
            $result->toArray(),
            $result,
            'https://shop.example/index.php?route=checkout/success',
            $session
        );
        self::assertTrue($payload['terminal']);
        self::assertSame('https://shop.example/index.php?route=checkout/success', $payload['redirect_url']);
    }

    public function testProcess2DefinitiveCpFailureIsNotProcess2Sent(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(403, [
            'success' => false,
            'error' => 'forbidden',
            'message' => 'Forbidden',
            'data' => new \stdClass(),
        ]);
        $orders = new InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport);

        $result = $this->submitProduct($service, true);
        self::assertSame(FinancingTerminalNavigationSupport::STEP_CP_TERMINAL_FAILED, $result->step);
        self::assertSame(BankStatus::SEND_FAILED_CP, $result->errorCode);
        self::assertNotSame(BankStatus::SENT_PROCESS2, $result->errorCode);
        self::assertNotSame('process2_prepared', $result->step);

        $orderId = (int) $result->orderId;
        $db = PersistenceIntegrationHarness::connection();
        $bank = (new OrderBankStatusRepository($db))->findCurrentStatus(ProductFinancingTestHarness::STORE_ID, $orderId);
        self::assertNotNull($bank);
        self::assertSame(BankStatus::SEND_FAILED_CP, $bank['status_id']);
        self::assertSame(BankStatus::LABEL_SEND_FAILED_CP, $bank['status_label']);
        self::assertNotSame(BankStatus::LABEL_SENT_PROCESS2, $bank['status_label']);
        self::assertSame(0, $transport->countStatusPatches());
    }

    public function testAmbiguousTimeoutDoesNotBecomeDefinitiveCpFailure(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueTimeout();
        $orders = new InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport);

        try {
            $this->submitProduct($service, false);
            self::fail('Expected ambiguous CP timeout exception');
        } catch (ProductFinancingFlowException $exception) {
            self::assertSame(ControlPanelErrorClass::TIMEOUT, $exception->errorCode());
        }

        $orderId = $orders->lastOrderId();
        $row = $this->attempts->findByOrderId(ProductFinancingTestHarness::STORE_ID, $orderId);
        self::assertSame(FinancingAttemptState::CP_OUTCOME_UNKNOWN, $row['state']);
        self::assertNull($row['control_panel_order_id']);

        $db = PersistenceIntegrationHarness::connection();
        $bank = (new OrderBankStatusRepository($db))->findCurrentStatus(ProductFinancingTestHarness::STORE_ID, $orderId);
        self::assertNull($bank);
        self::assertSame(0, $transport->countStatusPatches());
        self::assertSame(1, $orders->historyCountFor($orderId));
    }

    public function testMachineCodeInvalidPayloadIsDefinitive(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueueJson(422, [
            'success' => false,
            'error' => 'invalid_payload',
            'message' => 'phone required',
            'data' => new \stdClass(),
        ]);
        $orders = new InMemoryCheckoutOrderAdapter();
        $service = ProductFinancingTestHarness::submissionService($this->attempts, $orders, $transport);
        $result = $this->submitProduct($service, false);
        self::assertSame(FinancingTerminalNavigationSupport::STEP_CP_TERMINAL_FAILED, $result->step);
        $bank = (new OrderBankStatusRepository(PersistenceIntegrationHarness::connection()))
            ->findCurrentStatus(ProductFinancingTestHarness::STORE_ID, (int) $result->orderId);
        self::assertSame(BankStatus::SEND_FAILED_CP, $bank['status_id'] ?? null);
    }

    public function testUnitBareEndpointRejectionBecomesCpHttpException(): void
    {
        foreach ([403, 404, 405, 410] as $status) {
            $transport = new FakeCpHttpTransport();
            $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
            $transport->enqueue($status, 'Not Found');
            $client = $this->client($transport);
            try {
                $client->createOrder(['order_id' => '1', 'unicid' => Phase4TestHarness::TEST_UNICID]);
                self::fail('Expected CpHttpException for HTTP ' . $status);
            } catch (CpHttpException $exception) {
                self::assertSame($status, $exception->getStatusCode());
                self::assertFalse($exception->isCanonicalFailure());
            }
        }
    }

    public function testUnitAmbiguous5xxBareBodyStaysMalformed(): void
    {
        $transport = new FakeCpHttpTransport();
        $transport->enqueueJson(200, Phase4TestHarness::loginSuccessPayload());
        $transport->enqueue(500, 'not-json');
        $client = $this->client($transport);
        $this->expectException(CpMalformedJsonException::class);
        $client->createOrder(['order_id' => '1', 'unicid' => Phase4TestHarness::TEST_UNICID]);
    }

    private function client(FakeCpHttpTransport $transport): ControlPanelClient
    {
        $settings = Phase4TestHarness::settings();
        Phase4TestHarness::prepareCredentials($settings);
        $cipher = Phase4TestHarness::cipher();

        return new ControlPanelClient(
            new ModuleCredentialsRepository($settings, $cipher),
            new CpTokenRepository($settings, $cipher, Phase4TestHarness::TEST_STORE_ID),
            $transport,
            Phase4TestHarness::TEST_SHOP_URL,
            Phase4TestHarness::TEST_STORE_ID,
            'https://cp.example.test/api/v1'
        );
    }

    /**
     * @return ProductFinancingResult
     */
    private function submitProduct(object $service, bool $process2): ProductFinancingResult
    {
        $line = ProductFinancingTestHarness::factory()->create(ProductFinancingTestHarness::STORE_ID, 42, 1, []);
        $actor = ProductFinancingTestHarness::actorBinding();
        $scheme = ProductFinancingTestHarness::defaultSchemeSelection();
        $selection = ProductFinancingTestHarness::selectionHash(
            $line,
            $scheme['scheme_key'],
            $scheme['scheme_type'],
            $scheme['kop_code'],
            $scheme['months'],
            $scheme['filter_id'],
            $scheme['first_installment'],
            $actor
        );
        $operation = ProductOperationIdentity::hash(ProductFinancingTestHarness::STORE_ID, 42, [], 1, 'BGN');
        $attempt = (new ProductSubmissionIssuer($this->attempts, new PersistenceClock()))
            ->issueOrReuse(
                ProductFinancingTestHarness::STORE_ID,
                $operation,
                $actor,
                $selection,
                null,
                PersistenceIntegrationHarness::TEST_UNICID
            );
        $token = (string) $attempt['submission_token'];

        $shop = ProductFinancingTestHarness::shop();
        $posted = ProductFinancingTestHarness::validPostedCustomer();
        if ($process2) {
            $shop['uni_proces'] = 1;
            $posted['egn'] = '1990011599';
            $posted['phone2'] = '0888123456';
        }

        return $service->submit(
            $shop,
            ProductFinancingTestHarness::STORE_ID,
            $token,
            $actor,
            'sess-a',
            0,
            1,
            42,
            1,
            [],
            'BGN',
            'standard',
            $scheme['scheme_type'],
            $scheme['kop_code'],
            $scheme['months'],
            $scheme['filter_id'],
            $scheme['scheme_key'],
            $scheme['first_installment'],
            $posted,
            'test-unicid',
            '2026-08-28 12:00:00',
            1,
            'bg-bg',
            1,
            1.0,
            'Store',
            'https://example.test/',
            'INV-',
            LockOwnerTokenGenerator::generate()
        );
    }
}
