<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\PersistenceIntegrationHarness;
use MtUniCredit\Tests\Support\Phase4TestHarness;
use Opencart\System\Library\Extension\MtUniCredit\BankStatus;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutSessionOrderGuard;
use Opencart\System\Library\Extension\MtUniCredit\FinancingAttemptRepository;
use Opencart\System\Library\Extension\MtUniCredit\FinancingLeasingPresenter;
use Opencart\System\Library\Extension\MtUniCredit\FinancingTerminalNavigationSupport;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\OrderBankStatusRepository;
use Opencart\System\Library\Extension\MtUniCredit\PostControlPanelLifecycleService;
use Opencart\System\Library\Extension\MtUniCredit\ProductFinancingResult;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfFailureClassification;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfFailureClassifier;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfLifecycleRepository;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfSessionCoordinator;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfSessionException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

/**
 * Phase 11C Remediation 08 — Checkout P1 definite SmartUCF reject:
 * visible commerce status + terminal Thank You (not Voided / not stay on Checkout).
 */
final class Phase11CCheckoutProcess1RejectTest extends TestCase
{
    private const FIXTURE_121 = '{"errorCode":121,"errorText":"Вече е регистрирана поръчка със същия номер","sucfOnlineSessionID":null}';

    public function testVersionFrozen(): void
    {
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    public function testCheckoutControllerAppliesPaymentStatusAndThankYouOnTerminalReject(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php'
        );

        self::assertStringContainsString('isSmartUcfTerminalFailure', $src);
        self::assertStringContainsString('applyCheckoutUniCreditOrderStatus', $src);
        self::assertStringContainsString('PAYMENT_ORDER_STATUS_SETTING', $src);
        self::assertMatchesRegularExpression(
            '/isSmartUcfTerminalFailure[\s\S]*?applyCheckoutUniCreditOrderStatus[\s\S]*?enrichTerminalPayload[\s\S]*?\[\'redirect\'\]/s',
            $src
        );
        self::assertStringContainsString('redirect_script_href', $src);
        self::assertStringContainsString('mt_uni_credit_redirect.js', $src);
    }

    public function testCheckoutTwigLoadsRedirectHelperBeforeCheckoutJs(): void
    {
        $twig = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/template/payment/mt_uni_credit.twig'
        );
        self::assertStringContainsString('redirect_script_href', $twig);
        self::assertStringContainsString('ensureScript(state.redirect_script_href', $twig);
        self::assertMatchesRegularExpression(
            '/redirect_script_href[\s\S]*?script_href/s',
            $twig
        );
    }

    public function testCheckoutJsTerminalRejectNavigatesBeforeInlineError(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js'
        );

        self::assertStringContainsString('navigateCheckoutTerminalThankYou', $js);
        self::assertStringContainsString('smartucf_terminal_failed', $js);
        self::assertMatchesRegularExpression(
            '/navigateCheckoutTerminalThankYou\(json\)[\s\S]*?redirectTerminal\s*=\s*true[\s\S]*?return;/s',
            $js
        );

        $confirmFnPos = strpos($js, 'async function confirmPayment');
        $terminalCallPos = strpos($js, 'navigateCheckoutTerminalThankYou(json)', $confirmFnPos !== false ? $confirmFnPos : 0);
        $inlineRejectPos = strpos(
            $js,
            'json.message || "Заявката не може да бъде обработена."',
            $confirmFnPos !== false ? $confirmFnPos : 0
        );
        self::assertNotFalse($confirmFnPos);
        self::assertNotFalse($terminalCallPos);
        self::assertNotFalse($inlineRejectPos);
        self::assertLessThan($inlineRejectPos, $terminalCallPos);

        self::assertMatchesRegularExpression(
            '/finally\s*\{[\s\S]*?if\s*\(\s*!redirectTerminal\s*\)[\s\S]*?setProcessing\(false\)/',
            $js
        );
    }

    public function testBankFailureDoesNotImplyVoidedCommerceStatusInControllerContract(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php'
        );
        // Terminal reject must call status apply BEFORE return — not skip to early return without it.
        self::assertMatchesRegularExpression(
            '/isSmartUcfTerminalFailure[\s\S]{0,400}applyCheckoutUniCreditOrderStatus/s',
            $src
        );
        self::assertStringNotContainsString('config_void_status_id', $src);
    }

    public function testStaleCartMutationStillClearsSessionOrderPointer(): void
    {
        $session = ['order_id' => 8801];
        self::assertTrue(CheckoutSessionOrderGuard::invalidateOnCartMutation($session));
        self::assertArrayNotHasKey('order_id', $session);

        $order = [
            'order_id'        => 8801,
            'total'           => 500.0,
            'currency_code'   => 'EUR',
            'order_status_id' => 16, // Voided draft — stale when cart changes
        ];
        $orderProducts = [
            ['order_product_id' => 1, 'product_id' => 10, 'quantity' => 1],
        ];
        $cartProducts = [
            [
                'product_id' => 10,
                'quantity'   => 2,
                'option'     => [],
            ],
        ];
        $getOptions = static fn(): array => [];
        $session2 = ['order_id' => 8801];
        self::assertTrue(CheckoutSessionOrderGuard::reconcileSessionOrder(
            $session2,
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            999.0,
            'EUR'
        ));
        self::assertArrayNotHasKey('order_id', $session2);
    }

    public function testMatchingCartKeepsOrderPointerEvenWhenVoidedDraft(): void
    {
        $order = [
            'order_id'          => 8802,
            'total'             => 500.0,
            'currency_code'     => 'EUR',
            'order_status_id'   => 16,
        ];
        $orderProducts = [
            ['order_product_id' => 1, 'product_id' => 10, 'quantity' => 1],
        ];
        $cartProducts = [
            [
                'product_id' => 10,
                'quantity'   => 1,
                'option'     => [['product_option_value_id' => 1]],
            ],
        ];
        $getOptions = static fn(): array => [
            ['product_option_value_id' => 1],
        ];
        $session = ['order_id' => 8802];
        self::assertFalse(CheckoutSessionOrderGuard::reconcileSessionOrder(
            $session,
            $order,
            $orderProducts,
            $getOptions,
            $cartProducts,
            500.0,
            'EUR'
        ));
        self::assertSame(8802, $session['order_id']);
    }

    public function testTerminalRejectPayloadHasThankYouAndSessionPointer(): void
    {
        $result = new ProductFinancingResult(
            false,
            FinancingTerminalNavigationSupport::STEP_SMARTUCF_TERMINAL_FAILED,
            8810,
            FinancingLeasingPresenter::SMARTUCF_TERMINAL_FAILURE_MESSAGE,
            false,
            'failed',
            77001,
            BankStatus::SEND_FAILED_SMARTUCF,
            '',
            false
        );

        $session = [];
        $thankYou = 'https://shop.example/index.php?route=checkout/success&language=bg-bg';
        $payload = FinancingTerminalNavigationSupport::enrichTerminalPayload(
            $result->toArray(),
            $result,
            $thankYou,
            $session
        );

        self::assertFalse($payload['success']);
        self::assertTrue($payload['terminal']);
        self::assertSame(FinancingTerminalNavigationSupport::STEP_SMARTUCF_TERMINAL_FAILED, $payload['step']);
        self::assertSame($thankYou, $payload['redirect_url']);
        self::assertSame(8810, $session['order_id']);
        self::assertSame(8810, $session['mt_uni_credit_success_order_id']);
        self::assertSame(BankStatus::SEND_FAILED_SMARTUCF, $payload['error_code']);
        self::assertStringContainsString('Не изпращайте поръчката повторно', $payload['message']);
    }

    public function testProductCartControllersStillEnrichThankYouWithoutCheckoutStatusHelper(): void
    {
        foreach (
            [
                'catalog/controller/module/mt_uni_credit_product.php',
                'catalog/controller/module/mt_uni_credit_cart.php',
            ] as $file
        ) {
            $src = (string) file_get_contents(dirname(__DIR__) . '/' . $file);
            self::assertStringContainsString('enrichTerminalPayload', $src, $file);
            self::assertStringNotContainsString('applyCheckoutUniCreditOrderStatus', $src, $file);
        }
    }

    public function testIntegrationDefiniteRejectWritesBankStatusAndTerminalStep(): void
    {
        if (!PersistenceIntegrationHarness::enabled()) {
            self::markTestSkipped('Integration database is unavailable.');
        }

        PersistenceIntegrationHarness::resetTables();
        $db = PersistenceIntegrationHarness::connection();
        (new \Opencart\System\Library\Extension\MtUniCredit\PersistenceSchemaInstaller($db))->installAll();

        $submission = OrderMaterializationTestHarness::productSubmission();
        $attempts = new FinancingAttemptRepository($db);
        $orderId = 8820;
        $row = $attempts->issueWithSubmissionToken(
            $submission->storeId,
            $submission->entryPoint,
            hash('sha256', 'checkout-p1-reject-op'),
            hash('sha256', 'checkout-p1-reject-actor'),
            hash('sha256', 'checkout-p1-reject-sel')
        );
        $attemptId = (int) $row['attempt_id'];
        $attempts->attachOrder($attemptId, $orderId);

        $transport = new \MtUniCredit\Tests\Support\FakeCpHttpTransport();
        $transport->enableAutoAuthAndCreate();
        $services = Phase4TestHarness::services($transport, null, $db, $submission->storeId);

        $coordinator = new SmartUcfSessionCoordinator(
            new SmartUcfLifecycleRepository($db),
            new class(self::FIXTURE_121) {
                public function __construct(private string $rawResponse) {}

                public function createSession(): array
                {
                    throw new SmartUcfSessionException(
                        'SmartUCF did not return a session identifier.',
                        false,
                        $this->rawResponse,
                        200,
                        SmartUcfSessionException::KIND_REMOTE
                    );
                }
            },
            new SmartUcfFailureClassifier(),
            new OrderBankStatusRepository($db),
            $services['client']
        );

        $thankYou = 'https://shop.example/index.php?route=checkout/success';
        $result = (new PostControlPanelLifecycleService($coordinator, null, $thankYou))->handle(
            $attemptId,
            $submission,
            $orderId,
            99120,
            mt_uni_credit_valid_shop_snapshot()
        );

        self::assertFalse($result->success);
        self::assertSame(FinancingTerminalNavigationSupport::STEP_SMARTUCF_TERMINAL_FAILED, $result->step);
        self::assertSame(BankStatus::SEND_FAILED_SMARTUCF, $result->errorCode);
        self::assertSame($thankYou, $result->redirectUrl);
        self::assertFalse($result->bankSubmitted);

        $status = $db->query(
            'SELECT `status_id` FROM `' . $db->getPrefix()
                . 'mt_uni_credit_order_bank_status` WHERE `order_id` = ' . $orderId
        );
        self::assertSame(BankStatus::SEND_FAILED_SMARTUCF, $status->row['status_id']);

        $lifecycle = (new SmartUcfLifecycleRepository($db))->findByAttempt($attemptId);
        self::assertSame(SmartUcfFailureClassification::CLASS_REMOTE_REJECT, $lifecycle['smartucf_error_class']);

        $session = [];
        $payload = FinancingTerminalNavigationSupport::enrichTerminalPayload(
            $result->toArray(),
            $result,
            $thankYou,
            $session
        );
        self::assertTrue($payload['terminal']);
        self::assertSame($orderId, $session['mt_uni_credit_success_order_id']);
        // Commerce != bank: bank failed does not encode Voided; status apply is Checkout controller duty.
        self::assertNotSame(0, $orderId);
        self::assertNotSame('Voided', $payload['error_code'] ?? '');
    }

    public function testSuccessRegressionStillPrefersBankRedirectInCheckoutJs(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js'
        );
        self::assertStringContainsString('navigateIfTrusted(json.redirect_url)', $js);
        self::assertStringNotContainsString('continuation === "thank_you"', $js);

        $terminalHelperPos = strpos($js, 'navigateCheckoutTerminalThankYou(json)');
        $bankPos = strpos($js, 'navigateIfTrusted(json.redirect_url)');
        self::assertNotFalse($terminalHelperPos);
        self::assertNotFalse($bankPos);
        // Terminal helper runs first; success bank path only when not terminal thank-you.
        self::assertLessThan($bankPos, $terminalHelperPos);
    }
}
