<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\EventRegistry;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ModuleEncryptionKeyProvider;
use Opencart\System\Library\Extension\MtUniCredit\ProductBuyCheckoutPreference;
use PHPUnit\Framework\TestCase;

/**
 * Phase 11C Remediation 09F — preference lifetime across stash → Checkout → shipping.
 *
 * Proven storefront failure: stash wrote preference, then concurrent common/cart.info
 * overwrote the OC4 DB session blob (REPLACE) and wiped the key.
 */
final class Phase11CProductBuyPreferenceLifetime09FTest extends TestCase
{
    public function testEmptyNativePaymentDoesNotClearPreference(): void
    {
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 42,
            'scheme_type' => 'standard',
            'kop_code'    => 'POS COM 50',
            'months'      => 4,
            'filter_id'   => 11,
            'scheme_key'  => 'standard|POS%20COM%2050|4|11',
        ]);
        unset($session['payment_method']);
        ProductBuyCheckoutPreference::clearIfPaymentChangedAway($session);
        self::assertNotNull(ProductBuyCheckoutPreference::load($session, 0));

        $session['payment_method'] = [];
        ProductBuyCheckoutPreference::clearIfPaymentChangedAway($session);
        self::assertNotNull(ProductBuyCheckoutPreference::load($session, 0));

        $session['payment_method'] = '';
        ProductBuyCheckoutPreference::clearIfPaymentChangedAway($session);
        self::assertNotNull(ProductBuyCheckoutPreference::load($session, 0));
    }

    public function testExplicitNonUniCreditPaymentClearsPreference(): void
    {
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 42,
            'scheme_type' => 'standard',
            'kop_code'    => 'POS COM 50',
            'months'      => 4,
            'filter_id'   => 11,
            'scheme_key'  => 'standard|POS%20COM%2050|4|11',
        ]);
        $session['payment_method'] = [
            'code' => 'jet.jet',
            'name' => 'PB',
        ];
        ProductBuyCheckoutPreference::clearIfPaymentChangedAway($session);
        self::assertArrayNotHasKey(ProductBuyCheckoutPreference::SESSION_KEY, $session);
    }

    public function testStoreIdZeroIsValidAndDoesNotClear(): void
    {
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 1,
            'scheme_type' => 'standard',
            'kop_code'    => 'K',
            'months'      => 4,
            'filter_id'   => 0,
        ]);
        $inspected = ProductBuyCheckoutPreference::inspect($session, 0);
        self::assertTrue($inspected['preference_present']);
        self::assertSame('', $inspected['clear_reason']);
        self::assertSame(0, $inspected['store_id']);
        self::assertNotNull(ProductBuyCheckoutPreference::load($session, 0));
    }

    /**
     * Models the proven race: late common/cart.info REPLACE wipes preference after stash;
     * signed handoff cookie restores it before Checkout shipping.
     */
    public function testSessionRaceWipeIsRecoveredFromHandoffCookie(): void
    {
        $secret = ModuleEncryptionKeyProvider::testSecretInput();
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 42,
            'scheme_type' => 'standard',
            'kop_code'    => 'POS COM 50',
            'months'      => 4,
            'filter_id'   => 11,
            'scheme_key'  => 'standard|POS%20COM%2050|4|11',
        ]);
        $pref = ProductBuyCheckoutPreference::load($session, 0);
        self::assertNotNull($pref);
        $cookie = ProductBuyCheckoutPreference::buildHandoffCookieValue($pref, $secret);
        self::assertNotSame('', $cookie);

        // Simulate concurrent common/cart.info last-write-wins wipe.
        unset($session[ProductBuyCheckoutPreference::SESSION_KEY]);
        unset($session['payment_method']);
        self::assertNull(ProductBuyCheckoutPreference::load($session, 0));

        $restored = ProductBuyCheckoutPreference::restoreFromHandoffCookie($session, 0, $cookie, $secret);
        self::assertTrue($restored);
        $again = ProductBuyCheckoutPreference::load($session, 0);
        self::assertNotNull($again);
        self::assertSame(4, (int) $again['months']);
        self::assertSame('standard|POS%20COM%2050|4|11', (string) $again['scheme_key']);
        self::assertTrue(!empty($again['prefer_payment']));
        self::assertSame(ModuleConstants::PAYMENT_OPTION_CODE, (string) $again['payment_code']);
        self::assertTrue(!empty($again['restored_from_cookie']));
    }

    public function testPreferenceSurvivesShippingLifecycleAfterRestore(): void
    {
        $secret = ModuleEncryptionKeyProvider::testSecretInput();
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 42,
            'scheme_type' => 'standard',
            'kop_code'    => 'POS COM 50',
            'months'      => 4,
            'filter_id'   => 11,
            'scheme_key'  => 'standard|POS%20COM%2050|4|11',
        ]);
        $cookie = ProductBuyCheckoutPreference::buildHandoffCookieValue(
            ProductBuyCheckoutPreference::load($session, 0) ?? [],
            $secret
        );

        // Stash → late cart.info wipe → Checkout restore → shipping unset payment.
        unset($session[ProductBuyCheckoutPreference::SESSION_KEY], $session['payment_method']);
        ProductBuyCheckoutPreference::restoreFromHandoffCookie($session, 0, $cookie, $secret);
        unset($session['payment_method'], $session['payment_methods']);

        $pref = ProductBuyCheckoutPreference::load($session, 0);
        self::assertNotNull($pref);
        self::assertTrue(ProductBuyCheckoutPreference::shouldPreferPayment($pref));
        self::assertSame(ModuleConstants::PAYMENT_OPTION_CODE, (string) $pref['payment_code']);
        self::assertSame('standard|POS%20COM%2050|4|11', (string) $pref['scheme_key']);
        self::assertSame(4, (int) $pref['months']);

        // Empty native payment after shipping must not clear.
        ProductBuyCheckoutPreference::clearIfPaymentChangedAway($session);
        self::assertNotNull(ProductBuyCheckoutPreference::load($session, 0));
    }

    public function testExactFourMonthSchemeNotMonthsOnly(): void
    {
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 42,
            'scheme_type' => 'standard',
            'kop_code'    => 'POS COM 50',
            'months'      => 4,
            'filter_id'   => 11,
            'scheme_key'  => 'standard|POS%20COM%2050|4|11',
        ]);
        $pref = ProductBuyCheckoutPreference::load($session, 0);
        self::assertNotNull($pref);
        self::assertSame('standard', (string) $pref['scheme_type']);
        self::assertSame('POS COM 50', (string) $pref['kop_code']);
        self::assertSame(4, (int) $pref['months']);
        self::assertSame(11, (int) $pref['filter_id']);
        self::assertNotSame('promo', (string) $pref['scheme_type']);
    }

    public function testHandoffCookieRejectsTamperingAndWrongStore(): void
    {
        $secret = ModuleEncryptionKeyProvider::testSecretInput();
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 1,
            'scheme_type' => 'standard',
            'kop_code'    => 'K',
            'months'      => 4,
            'filter_id'   => 0,
        ]);
        $cookie = ProductBuyCheckoutPreference::buildHandoffCookieValue(
            ProductBuyCheckoutPreference::load($session, 0) ?? [],
            $secret
        );
        self::assertNull(ProductBuyCheckoutPreference::parseHandoffCookieValue($cookie . 'x', 0, $secret));
        self::assertNull(ProductBuyCheckoutPreference::parseHandoffCookieValue($cookie, 1, $secret));
    }

    public function testProductJsWaitsForCartInfoBeforeStash(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js'
        );
        self::assertStringContainsString('stashAfterCartInfoSettles', $js);
        self::assertStringContainsString('common/cart.info', $js);
        self::assertMatchesRegularExpression(
            '/json\.success[\s\S]*?stashAfterCartInfoSettles\(scheme\)/',
            $js
        );
        self::assertStringNotContainsString('mtuc_trace', $js);
        self::assertStringNotContainsString('__MTUC_', $js);
    }

    public function testStashControllerSetsHandoffCookieAndClosesSessionWithoutTrace(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/module/mt_uni_credit_product.php'
        );
        self::assertStringContainsString('buildHandoffCookieValue', $src);
        self::assertStringContainsString('HANDOFF_COOKIE', $src);
        self::assertStringContainsString('session->close()', $src);
        self::assertStringNotContainsString('mtuc_trace', $src);
        self::assertStringNotContainsString('ProductBuyHandoffTrace', $src);
        self::assertStringNotContainsString('function handoffTrace', $src);
    }

    public function testCheckoutHooksRestoreHandoffCookieWithoutTrace(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_product_buy.php'
        );
        self::assertStringContainsString('restoreHandoffCookieIfNeeded', $src);
        self::assertStringNotContainsString('ProductBuyHandoffTrace', $src);
        self::assertStringNotContainsString('X-Mtuc-Trace', $src);
        self::assertStringNotContainsString('onShippingMethodSaveBefore', $src);
        self::assertStringNotContainsString('_mtuc_trace', $src);
    }

    public function testEventRegistryHasNoTraceOnlyShippingBeforeHook(): void
    {
        $codes = EventRegistry::eventCodes();
        self::assertNotContains('module_mt_uni_credit_before_shipping_method_save_buy', $codes);
        self::assertContains('module_mt_uni_credit_after_shipping_method_save_buy', $codes);
        self::assertContains('module_mt_uni_credit_after_payment_methods', $codes);
        self::assertContains('module_mt_uni_credit_before_checkout_handoff_js', $codes);
    }

    public function testTraceAssetsRemoved(): void
    {
        self::assertFileDoesNotExist(
            dirname(__DIR__) . '/system/library/product_buy_handoff_trace.php'
        );
        self::assertFileDoesNotExist(
            dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_09e_trace.js'
        );
    }
}
