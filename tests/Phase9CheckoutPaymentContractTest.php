<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\Calculator;
use Opencart\System\Library\Extension\MtUniCredit\CartContext;
use Opencart\System\Library\Extension\MtUniCredit\CartLine;
use Opencart\System\Library\Extension\MtUniCredit\CartSchemeResolver;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutOperationIdentity;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutFinancingEligibility;
use Opencart\System\Library\Extension\MtUniCredit\CheckoutSelectionHash;
use Opencart\System\Library\Extension\MtUniCredit\CurrencyGate;
use Opencart\System\Library\Extension\MtUniCredit\EventRegistry;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\OperationEntryPoint;
use Opencart\System\Library\Extension\MtUniCredit\PaymentIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ProductContext;
use PHPUnit\Framework\TestCase;

/**
 * Phase 9 — Checkout payment method contracts (discovery, identity, selection, OC4 routes).
 */
final class Phase9CheckoutPaymentContractTest extends TestCase
{
    public function testPaymentFilesExist(): void
    {
        $root = dirname(__DIR__);
        foreach ([
            '/catalog/model/payment/mt_uni_credit.php',
            '/catalog/controller/payment/mt_uni_credit.php',
            '/catalog/view/template/payment/mt_uni_credit.twig',
            '/catalog/view/javascript/mt_uni_credit_checkout.js',
            '/catalog/view/stylesheet/mt_uni_credit_checkout.css',
            '/catalog/model/module/mt_uni_credit_checkout.php',
            '/catalog/controller/event/mt_uni_credit_checkout_success.php',
            '/admin/controller/payment/mt_uni_credit.php',
            '/admin/view/template/payment/mt_uni_credit.twig',
            '/system/library/checkout_operation_identity.php',
            '/system/library/checkout_selection_hash.php',
            '/system/library/checkout_financing_eligibility.php',
            '/system/library/checkout_submission_issuer.php',
            '/system/library/checkout_financing_submission_service.php',
            '/docs/PHASE9.md',
        ] as $relative) {
            self::assertFileExists($root . $relative, $relative);
        }
    }

    public function testPaymentIdentityIsCanonical(): void
    {
        self::assertSame('mt_uni_credit.mt_uni_credit', ModuleConstants::PAYMENT_OPTION_CODE);
        self::assertSame('mt_uni_credit.mt_uni_credit', PaymentIdentity::optionCode());
        self::assertTrue(PaymentIdentity::matchesStoredPayment(['code' => 'mt_uni_credit.mt_uni_credit', 'name' => 'UniCredit']));
        self::assertFalse(PaymentIdentity::matchesStoredPayment(['code' => 'mt_uni_credit.checkout']));
        self::assertFalse(PaymentIdentity::matchesStoredPayment(['code' => 'uni_credit']));
    }

    public function testGetMethodsReturnsCanonicalOptionShape(): void
    {
        $model = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/payment/mt_uni_credit.php');
        self::assertStringContainsString('ModuleConstants::PAYMENT_OPTION_CODE', $model);
        self::assertStringContainsString('ModuleConstants::PAYMENT_CODE', $model);
        self::assertStringContainsString('isPaymentMethodEligible', $model);
        self::assertStringContainsString('function getMethods(array $address = []): array', $model);
    }

    public function testConfirmRouteUsesExistingOrderGateway(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php');
        self::assertStringContainsString('function confirm(): void', $controller);
        self::assertStringContainsString('function issueSubmission(): void', $controller);
        self::assertStringContainsString('CheckoutExistingOrderGateway', file_get_contents(
            dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_checkout.php'
        ));
        self::assertStringContainsString('CheckoutFinancingSubmissionService', file_get_contents(
            dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_checkout.php'
        ));
        self::assertStringContainsString('createSubmissionService', $controller);
        self::assertStringNotContainsString('addOrder(', $controller);
        self::assertStringContainsString('CheckoutOperationIdentity', $controller);
        self::assertStringContainsString('CheckoutSelectionHash', $controller);
        self::assertStringContainsString('ProductStorefrontCsrf', $controller);
    }

    public function testOperationIdentityStablePerOrder(): void
    {
        $a = CheckoutOperationIdentity::hash(0, 42);
        $b = CheckoutOperationIdentity::hash(0, 42);
        $c = CheckoutOperationIdentity::hash(0, 43);
        $d = CheckoutOperationIdentity::hash(1, 42);
        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
        self::assertNotSame($a, $d);
        self::assertSame(64, strlen($a));
    }

    public function testSelectionHashDeterministicAndSensitive(): void
    {
        $base = CheckoutSelectionHash::hash(
            0,
            10,
            hash('sha256', 'fp'),
            'BGN',
            1200.0,
            'standard|KOP|12|0',
            'standard',
            'KOP',
            12,
            0,
            100.0,
            hash('sha256', 'actor')
        );
        $same = CheckoutSelectionHash::hash(
            0,
            10,
            hash('sha256', 'fp'),
            'BGN',
            1200.0,
            'standard|KOP|12|0',
            'standard',
            'KOP',
            12,
            0,
            100.0,
            hash('sha256', 'actor')
        );
        $amountChanged = CheckoutSelectionHash::hash(
            0,
            10,
            hash('sha256', 'fp'),
            'BGN',
            1200.01,
            'standard|KOP|12|0',
            'standard',
            'KOP',
            12,
            0,
            100.0,
            hash('sha256', 'actor')
        );
        $firstChanged = CheckoutSelectionHash::hash(
            0,
            10,
            hash('sha256', 'fp'),
            'BGN',
            1200.0,
            'standard|KOP|12|0',
            'standard',
            'KOP',
            12,
            0,
            150.0,
            hash('sha256', 'actor')
        );
        self::assertSame($base, $same);
        self::assertNotSame($base, $amountChanged);
        self::assertNotSame($base, $firstChanged);
    }

    public function testEligibilityRejectsDisabledAndUnsupportedCurrency(): void
    {
        $eligibility = new CheckoutFinancingEligibility(
            new Calculator(),
            new CartSchemeResolver(new Calculator()),
            new CurrencyGate()
        );
        $cart = new CartContext([], 0.0);
        $shop = ['uni_status' => 1, 'uni_minstojnost' => 100, 'uni_maxstojnost' => 100000, 'uni_eur' => 3];

        self::assertFalse($eligibility->isEligible($shop, $cart, 'BGN', false, true));
        self::assertFalse($eligibility->isEligible($shop, $cart, 'BGN', true, false));
        self::assertFalse($eligibility->isEligible($shop, $cart, 'USD', true, true));
    }

    public function testEligibilityRequiresSchemes(): void
    {
        $eligibility = new CheckoutFinancingEligibility(
            new Calculator(),
            new CartSchemeResolver(new Calculator()),
            new CurrencyGate()
        );
        $product = new ProductContext(40, [20], 999.0);
        $line = new CartLine($product, 0, 1, 999.0, []);
        $cart = new CartContext([$line], 999.0);
        $shop = [
            'uni_status'      => 1,
            'uni_minstojnost' => 100,
            'uni_maxstojnost' => 100000,
            'uni_eur'         => 3,
            'uni_typekop'     => 0,
            'filters'         => [],
            'schemes'         => [],
        ];
        self::assertFalse($eligibility->isEligible($shop, $cart, 'EUR', true, true));
    }

    public function testSubmissionServiceUsesCheckoutEntryPoint(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/system/library/checkout_financing_submission_service.php');
        self::assertStringContainsString('OperationEntryPoint::CHECKOUT', $src);
        self::assertStringContainsString('CheckoutExistingOrderGateway', file_get_contents(
            dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_checkout.php'
        ));
        self::assertStringContainsString('existingOrderId', $src);
        self::assertStringContainsString('checkout_order_missing', $src);
        self::assertStringContainsString('checkout_order_changed', $src);
        self::assertStringContainsString('stale_selection', $src);
        self::assertStringContainsString("'checkout_payment'", $src);
    }

    public function testIssueWithSubmissionTokenAllowsCheckout(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/system/library/financing_attempt_repository.php');
        self::assertMatchesRegularExpression(
            '/issueWithSubmissionToken[\s\S]*?OperationEntryPoint::CHECKOUT/',
            $src
        );
    }

    public function testCheckoutSuccessEventRegistered(): void
    {
        $triggers = array_column(EventRegistry::definitions(), 'trigger');
        self::assertContains('catalog/view/common/success/before', $triggers);
        $codes = EventRegistry::eventCodes();
        self::assertContains(ModuleConstants::MODULE_SETTING_CODE . '_before_checkout_success_view', $codes);
    }

    public function testCheckoutJsHasNoConsoleAndSurvivesRerender(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js');
        foreach (['console.log(', 'console.info(', 'console.debug(', 'console.warn(', 'console.error('] as $call) {
            self::assertStringNotContainsString($call, $js);
        }
        self::assertStringContainsString('dataset.mtucBound', $js);
        self::assertStringContainsString('MutationObserver', $js);
        self::assertStringContainsString('Confirm must not share abort', $js);
        self::assertStringContainsString('resetOfferState', $js);
        self::assertStringContainsString('resetFirstInstallmentForSchemeChange', $js);
    }

    public function testTwigHasNativeConfirmButtonAndForm(): void
    {
        $twig = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/payment/mt_uni_credit.twig');
        self::assertStringContainsString('id="button-confirm"', $twig);
        self::assertStringContainsString('id="mt-uni-credit-checkout-root"', $twig);
        self::assertStringContainsString('id="mt-uni-credit-checkout-form"', $twig);
        self::assertStringContainsString('data-mtuc-schemes', $twig);
        self::assertStringContainsString('data-mtuc-consent-checkbox', $twig);
    }

    public function testPhase9SuccessCopyDoesNotClaimBankSubmission(): void
    {
        $bg = (string) file_get_contents(dirname(__DIR__) . '/catalog/language/bg-bg/payment/mt_uni_credit.php');
        self::assertStringContainsString('Данните са приети', $bg);
        self::assertStringContainsString('Следващата стъпка ще бъде финансирането', $bg);
        self::assertStringNotContainsString('одобрено', $bg);
        self::assertStringNotContainsString('изпратено към банката', $bg);
    }

    public function testAddressApiContractRemainsTwoArgInCheckoutModel(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/catalog/model/module/mt_uni_credit_checkout.php');
        self::assertMatchesRegularExpression(
            '/getAddress\(\s*\$customerId\s*,\s*\$addressId\s*\)/',
            $src
        );
    }

    public function testCheckoutEntryPointConstant(): void
    {
        self::assertSame('checkout', OperationEntryPoint::CHECKOUT);
        self::assertTrue(OperationEntryPoint::isValid(OperationEntryPoint::CHECKOUT));
    }
}
