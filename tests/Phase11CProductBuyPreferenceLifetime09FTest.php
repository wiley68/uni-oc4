<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\ModuleEncryptionKeyProvider;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ProductBuyCheckoutPreference;
use Opencart\System\Library\Extension\MtUniCredit\ProductBuyHandoffTrace;
use PHPUnit\Framework\TestCase;

/**
 * Phase 11C Remediation 09F — preference lifetime across stash → Checkout → shipping.
 *
 * Proven storefront failure: stash wrote preference, then concurrent common/cart.info
 * overwrote the OC4 DB session blob (REPLACE) and wiped the key. Checkout ?mtuc_trace=1
 * re-enabled diagnostics only — preference stayed absent at shipping.save.
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

        // Simulate concurrent common/cart.info last-write-wins wipe (preference gone, other keys remain).
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
    }

    public function testStashControllerSetsHandoffCookieAndClosesSession(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/module/mt_uni_credit_product.php'
        );
        self::assertStringContainsString('buildHandoffCookieValue', $src);
        self::assertStringContainsString('HANDOFF_COOKIE', $src);
        self::assertStringContainsString('session->close()', $src);
    }

    public function testCheckoutBeforeRestoresHandoffCookie(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_product_buy.php'
        );
        self::assertStringContainsString('restoreHandoffCookieIfNeeded', $src);
        self::assertStringContainsString('onShippingMethodSaveBefore', $src);
        self::assertStringContainsString('lifetimeCheckpoint', $src);
    }

    public function testLifetimeCheckpointIncludesSessionFingerprint(): void
    {
        $fp = ProductBuyHandoffTrace::sessionFingerprint('abc123sessionid');
        self::assertSame(8, strlen($fp));
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 1,
            'scheme_type' => 'standard',
            'kop_code'    => 'K',
            'months'      => 4,
            'filter_id'   => 0,
        ]);
        $checkpoint = ProductBuyHandoffTrace::lifetimeCheckpoint($session, 0, 'abc123sessionid');
        self::assertTrue($checkpoint['preference_present']);
        self::assertSame($fp, $checkpoint['session_fingerprint']);
        self::assertSame(ProductBuyCheckoutPreference::SESSION_KEY, $checkpoint['session_key']);
        self::assertSame(4, $checkpoint['months']);
    }

    public function testEventRegistryIncludesShippingSaveBefore(): void
    {
        $codes = \Opencart\System\Library\Extension\MtUniCredit\EventRegistry::eventCodes();
        self::assertContains('module_mt_uni_credit_before_shipping_method_save_buy', $codes);
    }
}
