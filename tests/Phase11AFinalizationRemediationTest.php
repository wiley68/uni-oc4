<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\BankStatus;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use PHPUnit\Framework\TestCase;

/**
 * Phase 11A finalization — redirect loader continuity + Process 1 bank status vocabulary.
 */
final class Phase11AFinalizationRemediationTest extends TestCase
{
    private function redirectJs(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_redirect.js'
        );
    }

    private function productJs(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js'
        );
    }

    private function cartJs(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_cart.js'
        );
    }

    private function checkoutJs(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js'
        );
    }

    public function testSharedRedirectHelperTrustsOnlyUcfinApplicationUrls(): void
    {
        $js = $this->redirectJs();
        self::assertStringContainsString('online.ucfin.bg', $js);
        self::assertStringContainsString('onlinetest.ucfin.bg', $js);
        self::assertStringContainsString('/sucf-online/Request/Start/', $js);
        self::assertStringContainsString('navigateIfTrusted', $js);
        self::assertStringContainsString('isTrustedApplicationRedirect', $js);
    }

    public function testProductCartCheckoutKeepLoaderOnTrustedRedirect(): void
    {
        foreach ([$this->productJs(), $this->cartJs(), $this->checkoutJs()] as $js) {
            self::assertStringContainsString('redirectTerminal', $js);
            self::assertStringContainsString('MtUniCreditRedirect', $js);
            self::assertStringContainsString('navigateIfTrusted', $js);
            self::assertMatchesRegularExpression(
                '/finally\s*\{[\s\S]*?if\s*\(\s*!redirectTerminal\s*\)/',
                $js
            );
            self::assertStringContainsString('setProcessing(false)', $js);
        }
    }

    public function testFailurePathStillClearsLoader(): void
    {
        foreach ([$this->productJs(), $this->cartJs()] as $js) {
            self::assertStringContainsString("submitError.textContent = 'Заявката не може да бъде обработена.'", $js);
            self::assertMatchesRegularExpression(
                '/} catch \(error\) \{[\s\S]*?updateSubmitState\(false\);[\s\S]*?\} finally \{[\s\S]*?if \(!redirectTerminal\)/',
                $js
            );
        }
        $checkout = $this->checkoutJs();
        self::assertMatchesRegularExpression(
            '/} catch \(error\) \{[\s\S]*?updateConfirmState\(\);[\s\S]*?\} finally \{[\s\S]*?if \(!redirectTerminal\)/',
            $checkout
        );
    }

    public function testRedirectHelperLoadedBeforeEntryPointScripts(): void
    {
        $product = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_product_controller.php'
        );
        $cart = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_cart_controller.php'
        );
        $checkout = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php'
        );
        foreach ([$product, $cart, $checkout] as $src) {
            $helperPos = strpos($src, 'mt_uni_credit_redirect.js');
            self::assertNotFalse($helperPos);
        }
        self::assertLessThan(
            strpos($product, 'mt_uni_credit_product.js'),
            strpos($product, 'mt_uni_credit_redirect.js')
        );
        self::assertLessThan(
            strpos($cart, 'mt_uni_credit_cart.js'),
            strpos($cart, 'mt_uni_credit_redirect.js')
        );
        self::assertLessThan(
            strpos($checkout, 'mt_uni_credit_checkout.js'),
            strpos($checkout, 'mt_uni_credit_redirect.js')
        );
    }

    public function testProcess1VocabularyAndVersionFrozen(): void
    {
        self::assertSame('2.0.2', ModuleConstants::VERSION);
        self::assertSame('bank_sent_process1', BankStatus::SENT_PROCESS1);
        self::assertSame('Изпратен Банка - Процес 1', BankStatus::LABEL_SENT_PROCESS1);
        self::assertSame('bank_sent_process2', BankStatus::SENT_PROCESS2);
        self::assertSame('Неуспешно изпратен Банка - SmartUCF', BankStatus::LABEL_SEND_FAILED_SMARTUCF);

        $coordinator = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/smart_ucf_session_coordinator.php'
        );
        self::assertStringContainsString('BankStatus::process1Sent()', $coordinator);
        self::assertStringContainsString('substr((string) $localOrderId, 0, 13)', $coordinator);
        self::assertStringContainsString('ERROR_CP_BANK_STATUS_SYNC_PENDING', $coordinator);
        self::assertStringNotContainsString('BankStatus::SENT_PROCESS2', $coordinator);
        self::assertStringContainsString('persistProcess1BankStatus($attemptId', $coordinator);
    }

    public function testCoordinatorSourceUsesShopOrderIdForCpPatch(): void
    {
        $client = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/control_panel_client.php'
        );
        self::assertStringContainsString('Shop order identifier', $client);
        self::assertStringContainsString("'order_id' => \$shopOrderId", $client);
    }
}
