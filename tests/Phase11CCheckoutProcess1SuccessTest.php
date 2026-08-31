<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\BankStatus;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelOrderLifecycleService;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingTerminalNavigationSupport;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\OrderBankStatusRepository;
use Opencart\System\Library\Extension\MtUniCredit\PostControlPanelLifecycleService;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingResult;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfFailureClassifier;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleRepository;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfSessionCoordinator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

/**
 * Phase 11C Remediation 06 — Checkout Process 1 success → local Thank You (no mixed messages).
 */
final class Phase11CCheckoutProcess1SuccessTest extends TestCase
{
    public function testProcess1SuccessWithValidSessionProducesBankRedirectResult(): void
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
            hash('sha256', 'checkout-p1-success-op'),
            hash('sha256', 'checkout-p1-success-actor'),
            hash('sha256', 'checkout-p1-success-sel')
        );
        $orderId = 9601;
        $attempts->attachOrder((int) $row['attempt_id'], $orderId);

        $transport = new \MtUniCredit\Tests\Support\FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate();
        $services = Phase4TestHarness::services($transport, null, $db, $submission->storeId);

        $sessionId = 'session-checkout-p1-success-01';
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
                        'raw_request' => '{"orderNo":"9601"}',
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

        $thankYou = 'https://shop.example/index.php?route=checkout/success';
        $service = new PostControlPanelLifecycleService($coordinator, null, $thankYou);
        $result = $service->handle(
            (int) $row['attempt_id'],
            $submission,
            $orderId,
            88001,
            mt_uni_credit_valid_shop_snapshot(),
            false
        );

        self::assertTrue($result->success);
        self::assertSame(FinancingTerminalNavigationSupport::STEP_BANK_REDIRECT, $result->step);
        self::assertTrue($result->bankSubmitted);
        self::assertSame($bankUrl, $result->redirectUrl);
        self::assertSame(ControlPanelOrderLifecycleService::CUSTOMER_SUCCESS_MESSAGE, $result->message);
        self::assertTrue(FinancingTerminalNavigationSupport::isCheckoutProcess1Success($result));

        $status = $db->query(
            'SELECT `status_id` FROM `' . $db->getPrefix() . 'mt_uni_credit_order_bank_status` WHERE `order_id` = ' . $orderId
        );
        self::assertSame(BankStatus::SENT_PROCESS1, $status->row['status_id']);
    }

    public function testCheckoutEnrichmentReplacesBankUrlWithLocalThankYou(): void
    {
        $result = new ProductFinancingResult(
            true,
            FinancingTerminalNavigationSupport::STEP_BANK_REDIRECT,
            9602,
            ControlPanelOrderLifecycleService::CUSTOMER_SUCCESS_MESSAGE,
            false,
            'completed',
            88002,
            null,
            'https://onlinetest.ucfin.bg/sucf-online/Request/Start/session-x',
            true
        );

        $payload = $result->toArray();
        self::assertStringContainsString('ucfin.bg', (string) $payload['redirect_url']);

        $session = [];
        $thankYou = 'https://open40.example/index.php?route=checkout/success&language=bg-bg';
        $enriched = FinancingTerminalNavigationSupport::enrichTerminalPayload(
            $payload,
            $result,
            $thankYou,
            $session,
            true
        );

        self::assertSame($thankYou, $enriched['redirect_url']);
        self::assertSame($thankYou, $enriched['redirect']);
        self::assertTrue($enriched['terminal']);
        self::assertSame('thank_you', $enriched['continuation']);
        self::assertSame('success', $enriched['outcome']);
        self::assertSame(9602, $session['order_id']);
        self::assertSame(9602, $session['mt_uni_credit_success_order_id']);
        self::assertStringNotContainsString('ucfin.bg', (string) $enriched['redirect_url']);
        self::assertSame(ControlPanelOrderLifecycleService::CUSTOMER_SUCCESS_MESSAGE, $enriched['message']);
        self::assertArrayNotHasKey('error_code', $enriched);
    }

    public function testProductCartEnrichmentKeepsBankRedirectUrl(): void
    {
        $bankUrl = 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/session-product';
        $result = new ProductFinancingResult(
            true,
            FinancingTerminalNavigationSupport::STEP_BANK_REDIRECT,
            9603,
            ControlPanelOrderLifecycleService::CUSTOMER_SUCCESS_MESSAGE,
            false,
            'completed',
            88003,
            null,
            $bankUrl,
            true
        );

        $session = [];
        $enriched = FinancingTerminalNavigationSupport::enrichTerminalPayload(
            $result->toArray(),
            $result,
            'https://shop.example/index.php?route=checkout/success',
            $session,
            false
        );

        self::assertSame($bankUrl, $enriched['redirect_url']);
        self::assertArrayNotHasKey('terminal', $enriched);
        self::assertArrayNotHasKey('order_id', $session);
    }

    public function testCheckoutJsNavigatesTerminalBeforeMixedMessages(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js');

        self::assertMatchesRegularExpression(
            '/navigateTerminalThankYou\(json\.redirect_url\)[\s\S]*?redirectTerminal\s*=\s*true[\s\S]*?return;/',
            $js
        );
        self::assertMatchesRegularExpression(
            '/json\.terminal\s*===\s*true[\s\S]*?bank_redirect[\s\S]*?redirectTerminal\s*=\s*true[\s\S]*?location\.assign/',
            $js
        );
        self::assertMatchesRegularExpression(
            '/finally\s*\{[\s\S]*?if\s*\(\s*!redirectTerminal\s*\)[\s\S]*?setProcessing\(false\)/',
            $js
        );

        // Success banner must not precede terminal navigation for bank_redirect.
        $terminalAssignPos = strpos($js, 'json.continuation === "thank_you"');
        $successBannerPos = strpos($js, '[data-mtuc-success-message]');
        self::assertNotFalse($terminalAssignPos);
        self::assertNotFalse($successBannerPos);
        self::assertLessThan($successBannerPos, $terminalAssignPos);

        // Contradictory pair must not appear in the same success branch before navigation.
        self::assertDoesNotMatchRegularExpression(
            '/data-mtuc-success-message[\s\S]{0,400}Заявката не може да бъде обработена/',
            $js
        );
    }

    public function testCheckoutControllerPassesCheckoutThankYouFlag(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php');
        self::assertMatchesRegularExpression(
            '/enrichTerminalPayload\(\s*\$payload,\s*\$result,\s*\$thankYouUrl,\s*\$this->session->data,\s*true\s*\)/',
            $src
        );
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    public function testSuccessfulPayloadCannotCarryGenericErrorCode(): void
    {
        $result = new ProductFinancingResult(
            true,
            FinancingTerminalNavigationSupport::STEP_BANK_REDIRECT,
            9604,
            ControlPanelOrderLifecycleService::CUSTOMER_SUCCESS_MESSAGE,
            false,
            'completed',
            null,
            null,
            'https://onlinetest.ucfin.bg/sucf-online/Request/Start/s1',
            true
        );
        $session = [];
        $payload = FinancingTerminalNavigationSupport::enrichTerminalPayload(
            $result->toArray(),
            $result,
            'https://shop.example/index.php?route=checkout/success',
            $session,
            true
        );

        self::assertTrue($payload['success']);
        self::assertArrayNotHasKey('error_code', $payload);
        self::assertStringNotContainsString('Заявката не може да бъде обработена', json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('checkout/success', (string) $payload['redirect_url']);
    }
}
