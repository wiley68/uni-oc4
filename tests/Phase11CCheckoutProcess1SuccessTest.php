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
 * Phase 11C Remediation 06A — Checkout Process 1 success → trusted SmartUCF bank redirect (not Thank You).
 */
final class Phase11CCheckoutProcess1SuccessTest extends TestCase
{
    private const TRUSTED_BANK_URL = 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/session-checkout-p1';

    public function testProcess1SuccessLifecycleReturnsBankRedirectUrl(): void
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
            hash('sha256', 'checkout-p1-bank-op'),
            hash('sha256', 'checkout-p1-bank-actor'),
            hash('sha256', 'checkout-p1-bank-sel')
        );
        $orderId = 9701;
        $attempts->attachOrder((int) $row['attempt_id'], $orderId);

        $transport = new \MtUniCredit\Tests\Support\FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate();
        $services = Phase4TestHarness::services($transport, null, $db, $submission->storeId);

        $sessionId = 'session-checkout-p1';
        $bankUrl = self::TRUSTED_BANK_URL;
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
                        'raw_request' => '{"orderNo":"9701"}',
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

        $service = new PostControlPanelLifecycleService($coordinator, null, 'https://shop.example/checkout/success');
        $result = $service->handle(
            (int) $row['attempt_id'],
            $submission,
            $orderId,
            89001,
            mt_uni_credit_valid_shop_snapshot(),
            false
        );

        self::assertTrue($result->success);
        self::assertSame('bank_redirect', $result->step);
        self::assertTrue($result->bankSubmitted);
        self::assertSame($bankUrl, $result->redirectUrl);

        $status = $db->query(
            'SELECT `status_id` FROM `' . $db->getPrefix() . 'mt_uni_credit_order_bank_status` WHERE `order_id` = ' . $orderId
        );
        self::assertSame(BankStatus::SENT_PROCESS1, $status->row['status_id']);
    }

    public function testCheckoutEnrichmentPreservesBankRedirectNotThankYou(): void
    {
        $bankUrl = self::TRUSTED_BANK_URL;
        $result = new ProductFinancingResult(
            true,
            'bank_redirect',
            9702,
            ControlPanelOrderLifecycleService::CUSTOMER_SUCCESS_MESSAGE,
            false,
            'completed',
            89002,
            null,
            $bankUrl,
            true
        );

        $session = [];
        $thankYou = 'https://open40.example/index.php?route=checkout/success&language=bg-bg';
        $enriched = FinancingTerminalNavigationSupport::enrichTerminalPayload(
            $result->toArray(),
            $result,
            $thankYou,
            $session
        );

        self::assertSame($bankUrl, $enriched['redirect_url']);
        self::assertStringContainsString('ucfin.bg', (string) $enriched['redirect_url']);
        self::assertStringNotContainsString('checkout/success', (string) $enriched['redirect_url']);
        self::assertArrayNotHasKey('terminal', $enriched);
        self::assertArrayNotHasKey('order_id', $session);
    }

    public function testProcess2EnrichmentStillUsesThankYou(): void
    {
        $result = new ProductFinancingResult(
            true,
            'process2_prepared',
            9703,
            'Process 2 prepared',
            false,
            'process2_prepared',
            89003,
            null,
            '',
            false
        );

        $session = [];
        $thankYou = 'https://open40.example/index.php?route=checkout/success';
        $enriched = FinancingTerminalNavigationSupport::enrichTerminalPayload(
            $result->toArray(),
            $result,
            $thankYou,
            $session
        );

        self::assertSame($thankYou, $enriched['redirect_url']);
        self::assertSame(9703, $session['mt_uni_credit_success_order_id']);
    }

    public function testSharedRedirectHelperTrustsUcfinAndRejectsForeignHosts(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_redirect.js');

        self::assertStringContainsString('online.ucfin.bg', $js);
        self::assertStringContainsString('onlinetest.ucfin.bg', $js);
        self::assertStringContainsString('/sucf-online/Request/Start/', $js);
        self::assertStringContainsString('navigateIfTrusted', $js);
        self::assertStringContainsString('isTrustedApplicationRedirect', $js);
        self::assertStringContainsString('parsed.username || parsed.password', $js);
        self::assertStringContainsString('parsed.search || parsed.hash', $js);
    }

    public function testCheckoutJsMatchesProductCartBankRedirectBeforeSuccessBanner(): void
    {
        $checkout = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js');
        $product = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
        $cart = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_cart.js');

        foreach ([$checkout, $product, $cart] as $js) {
            self::assertMatchesRegularExpression(
                '/navigateTerminalThankYou\(json\.redirect_url\)[\s\S]*?redirectTerminal\s*=\s*true[\s\S]*?return;/',
                $js
            );
            self::assertMatchesRegularExpression(
                '/navigateIfTrusted\(json\.redirect_url\)[\s\S]*?redirectTerminal\s*=\s*true[\s\S]*?return;/',
                $js
            );
            self::assertMatchesRegularExpression(
                '/finally\s*\{[\s\S]*?if\s*\(\s*!redirectTerminal\s*\)[\s\S]*?setProcessing\(false\)/',
                $js
            );
        }

        $bankNavPos = strpos($checkout, 'navigateIfTrusted(json.redirect_url)');
        $successBannerPos = strpos($checkout, '[data-mtuc-success-message]');
        self::assertNotFalse($bankNavPos);
        self::assertNotFalse($successBannerPos);
        self::assertLessThan($successBannerPos, $bankNavPos);

        self::assertStringNotContainsString('continuation === "thank_you"', $checkout);
        self::assertStringNotContainsString('json.step === "bank_redirect"', $checkout);
        self::assertDoesNotMatchRegularExpression(
            '/data-mtuc-success-message[\s\S]{0,500}Заявката не може да бъде обработена/',
            $checkout
        );
    }

    public function testCheckoutControllerDoesNotForceThankYouForBankRedirect(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php');
        self::assertStringNotContainsString('checkoutProcess1ToThankYou', $src);
        self::assertStringNotContainsString('isCheckoutProcess1Success', $src);
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    public function testProductCartPayloadKeepsBankUrl(): void
    {
        $bankUrl = self::TRUSTED_BANK_URL;
        $result = new ProductFinancingResult(
            true,
            'bank_redirect',
            9704,
            ControlPanelOrderLifecycleService::CUSTOMER_SUCCESS_MESSAGE,
            false,
            'completed',
            null,
            null,
            $bankUrl,
            true
        );

        $session = [];
        $payload = FinancingTerminalNavigationSupport::enrichTerminalPayload(
            $result->toArray(),
            $result,
            'https://shop.example/index.php?route=checkout/success',
            $session
        );

        self::assertSame($bankUrl, $payload['redirect_url']);
        self::assertTrue($payload['success']);
        self::assertArrayNotHasKey('error_code', $payload);
    }
}
