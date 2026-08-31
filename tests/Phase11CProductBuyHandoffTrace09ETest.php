<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\ProductBuyCheckoutPreference;
use Opencart\System\Library\Extension\MtUniCredit\ProductBuyHandoffTrace;
use PHPUnit\Framework\TestCase;

/**
 * Phase 11C Remediation 09E — temporary browser-trace contracts (no business-logic change).
 */
final class Phase11CProductBuyHandoffTrace09ETest extends TestCase
{
    public function testTraceLibraryBuildAndJsonKey(): void
    {
        self::assertSame('09E-dd3c0d8-trace1', ProductBuyHandoffTrace::BUILD);
        self::assertSame('_mtuc_trace', ProductBuyHandoffTrace::JSON_KEY);
    }

    public function testTraceEnableViaQueryDoesNotAffectDisabledSession(): void
    {
        $session = [];
        self::assertFalse(ProductBuyHandoffTrace::isEnabled($session));
        ProductBuyHandoffTrace::captureRequest($session, [], []);
        self::assertFalse(ProductBuyHandoffTrace::isEnabled($session));
        ProductBuyHandoffTrace::captureRequest($session, ['mtuc_trace' => '1'], []);
        self::assertTrue(ProductBuyHandoffTrace::isEnabled($session));
        ProductBuyHandoffTrace::captureRequest($session, ['mtuc_trace' => '0'], []);
        self::assertFalse(ProductBuyHandoffTrace::isEnabled($session));
    }

    public function testPreferenceSnapshotIsNonPii(): void
    {
        $snap = ProductBuyHandoffTrace::preferenceSnapshot([
            'prefer_payment' => true,
            'payment_code'   => 'mt_uni_credit.mt_uni_credit',
            'scheme_key'     => 'standard|KOP|4|0',
            'months'         => 4,
            'customer_email' => 'secret@example.com',
            'egn'            => '0000000000',
        ]);
        self::assertTrue($snap['prefer_payment']);
        self::assertSame(4, $snap['months']);
        self::assertArrayNotHasKey('customer_email', $snap);
        self::assertArrayNotHasKey('egn', $snap);
        self::assertSame('mt_uni_credit.mt_uni_credit', $snap['payment_code']);
    }

    public function testWrapIncrementsSequence(): void
    {
        $session = [];
        ProductBuyHandoffTrace::enable($session);
        $a = ProductBuyHandoffTrace::wrap($session, 'hook_a', ['months' => 4]);
        $b = ProductBuyHandoffTrace::wrap($session, 'hook_b', ['months' => 4]);
        self::assertSame(1, $a['seq']);
        self::assertSame(2, $b['seq']);
        self::assertSame('hook_a', $a['hook']);
    }

    public function testPaymentOrderCodesFlattenOptionCodes(): void
    {
        $codes = ProductBuyHandoffTrace::paymentOrderCodes([
            'jet' => [
                'option' => [
                    'jet' => ['code' => 'jet.jet', 'name' => 'PB'],
                ],
            ],
            'mt_uni_credit' => [
                'option' => [
                    'mt_uni_credit' => ['code' => 'mt_uni_credit.mt_uni_credit', 'name' => 'UC'],
                ],
            ],
        ]);
        self::assertSame(['jet.jet', 'mt_uni_credit.mt_uni_credit'], $codes);
    }

    public function testEventControllerWiresTraceMarkersAndResponseMutation(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_product_buy.php'
        );
        self::assertStringContainsString('ProductBuyHandoffTrace', $src);
        self::assertStringContainsString('PRODUCT_BUY_STASH_EXECUTED', (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/module/mt_uni_credit_product.php'
        ));
        self::assertStringContainsString('SHIPPING_AFTER_ENTER', $src);
        self::assertStringContainsString('payment_method.getMethods/after', $src);
        self::assertStringContainsString('CHECKOUT_HANDOFF_INTENT_PRESENT', $src);
        self::assertStringContainsString('readJsonResponseBody', $src);
        self::assertStringContainsString('writeJsonResponseBody', $src);
        self::assertStringContainsString('getOutput()', $src);
        self::assertStringContainsString('setOutput(', $src);
        self::assertStringContainsString('ProductBuyHandoffTrace::JSON_KEY', $src);
        self::assertStringContainsString('attachTrace', $src);
    }

    public function testStashAndHandoffTraceEndpointExist(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/module/mt_uni_credit_product.php'
        );
        self::assertStringContainsString('function handoffTrace', $src);
        self::assertStringContainsString('HANDOFF_TRACE_CHECKPOINT', $src);
        self::assertStringContainsString('mtuc_trace=1', $src);
    }

    public function testJsBuildMarkersAndTracePropagation(): void
    {
        $product = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js'
        );
        $handoff = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout_handoff.js'
        );
        $trace = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_09e_trace.js'
        );
        self::assertStringContainsString('09E-dd3c0d8-trace1', $product);
        self::assertStringContainsString('mtuc_trace', $product);
        self::assertStringContainsString('__MTUC_LAST_STASH_TRACE', $product);
        self::assertStringContainsString('__MTUC_LAST_TRACE', $handoff);
        self::assertStringContainsString('_mtuc_trace', $handoff);
        self::assertStringContainsString('__MTUC_HANDOFF_BUILD', $trace);
        self::assertFileExists(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_09e_trace.js');
    }

    public function testOc4ResponseLifecycleSupportsAfterMutation(): void
    {
        $framework = dirname(__DIR__, 3) . '/system/framework.php';
        if (!is_file($framework)) {
            self::markTestSkipped('OpenCart framework.php not adjacent');
        }
        $src = (string) file_get_contents($framework);
        self::assertMatchesRegularExpression(
            '/trigger\(\'controller\/\'\s*\.\s*\$trigger\s*\.\s*\'\/after\'[\s\S]*?\$response->output\(\)/s',
            $src
        );
        // Action::execute does not re-setOutput after after-events — mutation in /after reaches browser.
        $action = dirname(__DIR__, 3) . '/system/engine/action.php';
        self::assertStringNotContainsString('setOutput', (string) file_get_contents($action));
    }

    public function testJetPbHasNoCheckoutPaymentAfterHooksInExtensionTree(): void
    {
        $jetEvents = glob(dirname(__DIR__, 2) . '/mt_jet_credit/catalog/controller/event/*.php') ?: [];
        $names = array_map('basename', $jetEvents);
        self::assertContains('mt_jet_credit_product_controller.php', $names);
        self::assertContains('mt_jet_credit_cart_controller.php', $names);
        foreach ($jetEvents as $file) {
            $src = (string) file_get_contents($file);
            self::assertStringNotContainsString('payment_method.getMethods', $src);
            self::assertStringNotContainsString('shipping_method.save', $src);
            self::assertStringNotContainsString('payment_method.save', $src);
        }
    }

    public function testEnrichStillRequiresPreferenceNotTrace(): void
    {
        // Guard: 09E must not change matcher — enrich only applies when prefer_payment intent exists.
        $session = [];
        ProductBuyHandoffTrace::enable($session);
        $json = [
            'payment_methods' => [
                'jet' => [
                    'name'   => 'PB',
                    'option' => ['jet' => ['code' => 'jet.jet', 'name' => 'PB']],
                ],
                'mt_uni_credit' => [
                    'name'   => 'UC',
                    'option' => [
                        'mt_uni_credit' => [
                            'code' => 'mt_uni_credit.mt_uni_credit',
                            'name' => 'UC',
                        ],
                    ],
                ],
            ],
        ];
        $out = ProductBuyCheckoutPreference::enrichPaymentMethodsResponse($session, $json, 0);
        $order = ProductBuyHandoffTrace::paymentOrderCodes($out['payment_methods']);
        self::assertSame(['jet.jet', 'mt_uni_credit.mt_uni_credit'], $order);
        self::assertArrayNotHasKey('payment_method', $session);
    }
}
