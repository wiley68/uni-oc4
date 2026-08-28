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
        self::assertStringNotContainsString('name="egn"', $modal);
        self::assertStringContainsString('data-mtuc-apply', $modal);
        self::assertStringContainsString('data-mtuc-secondary', $modal);
        self::assertStringContainsString('data-mtuc-submit', $modal);
    }

    public function testPopupPresenterExposesSecondaryButtonContract(): void
    {
        $presenter = new ProductModalPresenter(new ConsentResolver());
        $buy = $presenter->present([], [], 'buy');
        $cart = $presenter->present([], [], 'add_to_cart');

        self::assertSame('buy', $buy['button_action']);
        self::assertSame('Купи', $buy['secondary_label']);
        self::assertSame('add_to_cart', $cart['button_action']);
        self::assertSame('Добави в количката', $cart['secondary_label']);
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
}
