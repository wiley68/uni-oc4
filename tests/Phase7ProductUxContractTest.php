<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\ProductModalPresenter;
use Opencart\System\Library\Extension\MtUniCredit\ProductPopupCustomerPrefill;
use Opencart\System\Library\Extension\MtUniCredit\ProductPopupFormNormalizer;
use Opencart\System\Library\Extension\MtUniCredit\ConsentResolver;
use PHPUnit\Framework\TestCase;

/**
 * Established UniCredit Product UX contract — no unexplained third financing action.
 */
final class Phase7ProductUxContractTest extends TestCase
{
    public function testCalculatorHasOfferButtonsOnlyNoThirdFinancingAction(): void
    {
        $calc = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_product_calculator.twig');
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');

        self::assertStringContainsString('mt-uni-credit-product-calculator__button', $calc);
        self::assertStringContainsString('mt-uni-credit-product-calculator__button-title', $calc);
        self::assertStringContainsString('text_button_financing', $calc);
        self::assertStringNotContainsString('mt-uni-credit-open-modal', $calc);
        self::assertStringNotContainsString('mt-uni-credit-open-modal', $js);
        self::assertStringContainsString('data-offer-type', $calc);
    }

    public function testModalHasTwoStepCustomerFormAndReferenceFields(): void
    {
        $modal = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_product_modal.twig');

        self::assertStringContainsString('data-mtuc-step="1"', $modal);
        self::assertStringContainsString('data-mtuc-step="2"', $modal);
        self::assertStringContainsString('data-mtuc-step="3"', $modal);
        self::assertStringContainsString('name="firstname"', $modal);
        self::assertStringContainsString('name="lastname"', $modal);
        self::assertStringContainsString('name="address"', $modal);
        self::assertStringContainsString('name="phone"', $modal);
        self::assertStringContainsString('name="email"', $modal);
        // Process 2 EGN/phone2 only behind modal.process2 (Process 1 renders without them).
        self::assertStringContainsString('{% if mt_uni_credit.modal.process2 %}', $modal);
        self::assertMatchesRegularExpression(
            '/\{\%\s*if\s+mt_uni_credit\.modal\.process2\s*\%\}[\s\S]*name="egn"[\s\S]*name="phone2"/',
            $modal
        );
        self::assertStringContainsString('data-mtuc-apply', $modal);
        self::assertStringContainsString('data-mtuc-secondary', $modal);
        self::assertStringContainsString('data-mtuc-submit', $modal);
    }

    public function testPopupPresenterExposesSecondaryButtonContract(): void
    {
        $presenter = new ProductModalPresenter(new ConsentResolver());
        $buy = $presenter->present([], [], 'buy');
        $cart = $presenter->present([], [], 'add_to_cart');
        $process2 = $presenter->present(['uni_proces' => 1], [], 'buy');

        self::assertSame('buy', $buy['button_action']);
        self::assertSame('Купи', $buy['secondary_label']);
        self::assertFalse($buy['process2']);
        self::assertSame('add_to_cart', $cart['button_action']);
        self::assertSame('Добави в количката', $cart['secondary_label']);
        self::assertTrue($process2['process2']);
    }

    public function testPopupFormNormalizerMapsReferenceFieldNames(): void
    {
        $normalizer = new ProductPopupFormNormalizer();
        $normalized = $normalizer->normalize([
            'first_name' => 'Ivan',
            'last_name'  => 'Petrov',
            'phone'      => '0888000000',
            'address'    => 'ul. Test 1',
        ], [
            'country_id' => 33,
            'zone_id'    => 4239,
            'city'       => 'Sofia',
            'postcode'   => '1000',
        ]);

        self::assertSame('Ivan', $normalized['firstname']);
        self::assertSame('Petrov', $normalized['lastname']);
        self::assertSame('0888000000', $normalized['telephone']);
        self::assertSame('ul. Test 1', $normalized['address_1']);
        self::assertSame('Sofia', $normalized['city']);
    }

    public function testCustomerPrefillJoinsAddressLikeReference(): void
    {
        $prefill = new ProductPopupCustomerPrefill();
        $result = $prefill->present(true, ['email' => 'a@b.test'], [[
            'firstname'  => 'Ivan',
            'lastname'   => 'Petrov',
            'address_1'  => 'ul. Test 1',
            'city'       => 'Sofia',
            'postcode'   => '1000',
            'telephone'  => '0888',
            'address_id' => 5,
        ]]);

        self::assertSame('Ivan', $result['firstname']);
        self::assertStringContainsString('ul. Test 1', $result['address']);
        self::assertStringContainsString('Sofia', $result['address']);
        self::assertTrue($result['is_logged']);
    }

    public function testJsWiresSecondaryActionAndTwoStepFlow(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');

        self::assertStringContainsString('secondaryActionUsesNativeAddToCart', $js);
        self::assertStringContainsString('#button-cart', $js);
        self::assertStringContainsString('data-mtuc-apply', $js);
        self::assertStringContainsString('setStep(2)', $js);
        self::assertStringContainsString('setStep(3)', $js);
        self::assertStringContainsString('Локалната поръчка е подготвена', $js);
    }

    public function testAddToCartSecondaryClosesModalOnlyOnOpenCartSuccess(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
        $productTwig = (string) file_get_contents(
            dirname(__DIR__, 3) . '/catalog/view/template/product/product.twig'
        );

        // Native OC4.1 Product cart.add contract (authoritative success signal).
        self::assertStringContainsString("route=checkout/cart.add", $productTwig);
        self::assertStringContainsString("if (json['success'])", $productTwig);
        self::assertStringContainsString("id=\"button-cart\"", $productTwig);

        // UniCredit observes jQuery ajaxSuccess for cart.add, then reuses closeModal().
        self::assertStringContainsString('ajaxSuccess.mtUniCreditCart', $js);
        self::assertStringContainsString('bindNativeCartAddSuccessCloser', $js);
        self::assertStringContainsString('isCheckoutCartAddUrl', $js);
        self::assertStringContainsString('route=checkout/cart.add', $js);
        self::assertStringContainsString('json.success', $js);
        self::assertStringContainsString('closeModal()', $js);
        self::assertStringContainsString('awaitingNativeCartAdd', $js);
        self::assertStringContainsString('unbindNativeCartAddObserver', $js);
        self::assertStringContainsString("$(document).off('ajaxSuccess.mtUniCreditCart')", $js);

        // Still one native click — no UniCredit cart AJAX payload reconstruction.
        self::assertStringContainsString('cartBtn.click()', $js);
        self::assertStringNotContainsString('checkout/cart.add&', $js);
        self::assertStringNotContainsString('setTimeout(() => closeModal', $js);
        self::assertStringNotContainsString('setTimeout(closeModal', $js);

        // buy path unchanged.
        self::assertStringContainsString("!== 'buy'", $js);
        self::assertStringContainsString('checkout_url', $js);
        self::assertStringContainsString('window.location.href = checkoutUrl', $js);
    }

    public function testStep1CalcFrameHasAsymmetricUniCreditRadius(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/stylesheet/mt_uni_credit_product.css');
        $modal = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_product_modal.twig');

        self::assertStringContainsString('mt-uni-credit-product-calculator__popup-calc', $modal);
        self::assertStringContainsString('border-radius: 14.5px 14.5px 80px 14.5px', $css);
        self::assertMatchesRegularExpression(
            '/:is\(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal\)[^{]*\{[^}]*--mtuc-popup-red:\s*#ed1c24/s',
            $css
        );

        // bottom-right 80 > other corners 14.5
        self::assertGreaterThan(14.5, 80.0);
    }

    public function testStep1RightColumnValuesAndFirstInstallmentUsePopupRed(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/stylesheet/mt_uni_credit_product.css');
        $modal = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_product_modal.twig');

        self::assertStringContainsString('.mt-uni-credit-product-calculator__popup-value {', $css);
        self::assertStringContainsString('color: var(--mtuc-popup-red)', $css);
        self::assertStringContainsString('data-mtuc-first', $modal);
        self::assertStringContainsString(
            '.mt-uni-credit-product-calculator__popup-input {',
            $css
        );
        self::assertStringContainsString('border-bottom: 1px solid #b0b0b0', $css);
        // No generic form-control chrome on calc input/select.
        self::assertDoesNotMatchRegularExpression(
            '/:is\(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal\) \.mt-uni-credit-product-calculator__popup-input \{[^}]*border:\s*1px solid/s',
            $css
        );
    }

    public function testPromoSchemeLabelsUseMonthsThenDescription(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');

        self::assertStringContainsString('`${scheme.months} месеца`', $js);
        self::assertStringContainsString('label += ` - ${scheme.description}`', $js);
        self::assertStringNotContainsString('option.textContent = scheme.description', $js);
    }

    public function testStep1FooterHasExactlyThreeActionsWithLeftRightGroups(): void
    {
        $modal = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_product_modal.twig');
        $css = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/stylesheet/mt_uni_credit_product.css');

        // Extract Step 1 block only.
        self::assertMatchesRegularExpression(
            '/data-mtuc-step="1"[\s\S]*?popup-actions--step1[\s\S]*?popup-actions-left[\s\S]*?data-mtuc-dismiss[\s\S]*?data-mtuc-secondary[\s\S]*?popup-actions-right[\s\S]*?data-mtuc-apply/',
            $modal
        );

        $step1 = [];
        if (preg_match('/data-mtuc-step="1"[\s\S]*?(?=<div class="mt-uni-credit-product-calculator__step" data-mtuc-step="2")/', $modal, $step1) === 1) {
            $block = $step1[0];
            self::assertSame(1, substr_count($block, 'data-mtuc-dismiss'));
            self::assertSame(1, substr_count($block, 'data-mtuc-secondary'));
            self::assertSame(1, substr_count($block, 'data-mtuc-apply'));
            self::assertSame(0, substr_count($block, 'data-mtuc-submit'));
            self::assertSame(0, substr_count($block, 'data-mtuc-back'));
        } else {
            self::fail('Step 1 block not found');
        }

        self::assertStringContainsString('popup-actions-left', $css);
        self::assertStringContainsString('popup-actions-right', $css);
        self::assertStringContainsString('margin-left: auto', $css);
        // Not whole-footer flex-end for Step 1.
        self::assertStringNotContainsString(
            'popup-actions--step1 {\n  justify-content: flex-end',
            $css
        );
    }

    public function testLayeredPopupButtonContractMatchesReference(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/stylesheet/mt_uni_credit_product.css');

        self::assertStringContainsString('--mtuc-btn-offset: 4px', $css);
        self::assertStringContainsString('min-width: 140px', $css);
        self::assertStringContainsString('box-shadow: 6px 6px 7px rgba(105, 105, 105, .75)', $css);
        // Modal popup buttons use 6px layered radius — not offer-pill 9999px.
        self::assertMatchesRegularExpression(
            '/:is\(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal\) button\.mt-uni-credit-product-calculator__popup-button \{[^}]*border-radius:\s*6px/s',
            $css
        );
        self::assertDoesNotMatchRegularExpression(
            '/:is\(#mt-uni-credit-product-modal, #mt-uni-credit-cart-modal\) button\.mt-uni-credit-product-calculator__popup-button \{[^}]*border-radius:\s*9999px/s',
            $css
        );
    }
}
