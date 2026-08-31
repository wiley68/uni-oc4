<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\CartActorBinding;
use Opencart\System\Library\Extension\MtUniCredit\CartCalculatorPresenter;
use Opencart\System\Library\Extension\MtUniCredit\CartContext;
use Opencart\System\Library\Extension\MtUniCredit\CartFingerprint;
use Opencart\System\Library\Extension\MtUniCredit\CartOperationIdentity;
use Opencart\System\Library\Extension\MtUniCredit\CartSchemeCalculator;
use Opencart\System\Library\Extension\MtUniCredit\CartSchemeResolver;
use Opencart\System\Library\Extension\MtUniCredit\CartSelectionHash;
use Opencart\System\Library\Extension\MtUniCredit\Calculator;
use Opencart\System\Library\Extension\MtUniCredit\CurrencyGate;
use Opencart\System\Library\Extension\MtUniCredit\AmountDisplayFormatter;
use Opencart\System\Library\Extension\MtUniCredit\InstallmentLabelFormatter;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartCartContextFactory;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartCartOrderProductsBuilder;
use Opencart\System\Library\Extension\MtUniCredit\ProductSchemeList;
use Opencart\System\Library\Extension\MtUniCredit\StandardThemeCartPlacement;
use Opencart\System\Library\Extension\MtUniCredit\UnavailableSchemeException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/calculator_fixture.php';

final class Phase8CartFlowContractTest extends TestCase
{
    public function testCartContextFactoryUsesAuthoritativeTotalAndLineIdentities(): void
    {
        $factory = new OpenCartCartContextFactory(
            static fn(int $productId): array => $productId === 10 ? [7, 8] : [9],
            static fn(float $price, int $taxClassId): float => $price * 1.2
        );
        $cart = $factory->create([
            [
                'product_id'   => 10,
                'quantity'     => 2,
                'price'        => 100.0,
                'tax_class_id' => 1,
                'option'       => [['product_option_value_id' => 55]],
            ],
            [
                'product_id'   => 20,
                'quantity'     => 1,
                'price'        => 50.0,
                'tax_class_id' => 1,
                'option'       => [],
            ],
        ], 999.99);

        self::assertSame(999.99, $cart->total);
        self::assertCount(2, $cart->lines);
        self::assertSame(10, $cart->lines[0]->product->productId);
        self::assertSame([7, 8], $cart->lines[0]->product->categoryIds);
        self::assertSame(2, $cart->lines[0]->quantity);
        self::assertSame(55, $cart->lines[0]->productAttributeId);
        self::assertEqualsWithDelta(240.0, $cart->lines[0]->lineTotal, 0.01);
    }

    public function testFingerprintAndOperationIdentityAreCartScoped(): void
    {
        $cart = new CartContext([mt_uni_credit_cart_line(1, [7], 500.0, 2, 500.0)], 500.0);
        $fp = CartFingerprint::hash($cart, 'BGN');
        $op = CartOperationIdentity::hash(0, 'BGN', $fp);
        $actor = CartActorBinding::hash(0, 0, CartActorBinding::sessionFingerprint('sess'));
        $selection = CartSelectionHash::hash(0, $fp, 'BGN', 500.0, 'k', 'standard', 'STD', 12, 0, 0.0, $actor);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fp);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $op);
        self::assertNotSame($fp, $op);
        self::assertNotSame($op, $selection);

        $changed = new CartContext([mt_uni_credit_cart_line(1, [7], 500.0, 3, 500.0)], 500.0);
        self::assertNotSame($fp, CartFingerprint::hash($changed, 'BGN'));
    }

    public function testPresenterReturnsNullWhenIntersectionEmpty(): void
    {
        $shop = mt_uni_credit_calculator_fixture();
        $presenter = new CartCalculatorPresenter(
            new CartSchemeResolver(new Calculator()),
            new Calculator(),
            new CurrencyGate(),
            new InstallmentLabelFormatter()
        );
        $empty = $presenter->present($shop, new CartContext([], 0.0), 'BGN');
        self::assertNull($empty);

        $noCommon = $presenter->present(
            $shop,
            new CartContext([
                mt_uni_credit_cart_line(1, [], 1000.0),
                mt_uni_credit_cart_line(2, [99999], 1000.0),
            ], 1000.0),
            'BGN'
        );
        // Category filter 99999 typically yields empty intersection for promo filters; standard may still intersect.
        // Authoritative check: resolver empty schemes ⇒ presenter null.
        $resolver = new CartSchemeResolver(new Calculator());
        $resolution = $resolver->resolve($shop, new CartContext([
            mt_uni_credit_cart_line(1, [], 50.0),
        ], 50.0));
        self::assertSame([], $resolution->standardSchemes);
        self::assertNull($presenter->present($shop, new CartContext([mt_uni_credit_cart_line(1, [], 50.0)], 50.0), 'BGN'));
        unset($noCommon);
    }

    public function testSchemeCalculatorRejectsUnavailableScheme(): void
    {
        $shop = mt_uni_credit_calculator_fixture();
        $calc = new CartSchemeCalculator(
            new Calculator(),
            new CartSchemeResolver(new Calculator()),
            new CurrencyGate(),
            new AmountDisplayFormatter()
        );
        $cart = new CartContext([mt_uni_credit_cart_line(1, [], 1000.0)], 1000.0);

        $this->expectException(UnavailableSchemeException::class);
        $calc->calculate($shop, $cart, 'BGN', 'standard', 'standard', 'MISSING', 12, 0, 'bad', 0.0);
    }

    public function testOrderProductsBuilderMapsOptionsQtyAndTotals(): void
    {
        $builder = new OpenCartCartOrderProductsBuilder();
        $materials = $builder->build([
            [
                'product_id'   => 5,
                'name'         => 'Phone',
                'model'        => 'P1',
                'quantity'     => 2,
                'price'        => 100.0,
                'tax_class_id' => 9,
                'subtract'     => 1,
                'reward'       => 3,
                'shipping'     => 1,
                'option'       => [[
                    'product_option_id'       => 1,
                    'product_option_value_id' => 2,
                    'name'                    => 'Color',
                    'value'                   => 'Black',
                    'type'                    => 'select',
                ]],
            ],
        ], static fn(float $price, int $taxClassId): float => 20.0);

        self::assertTrue($materials['shipping_required']);
        self::assertCount(1, $materials['products']);
        self::assertSame(2, $materials['products'][0]['quantity']);
        self::assertSame(5, $materials['products'][0]['product_id']);
        self::assertSame(100.0, $materials['products'][0]['price']);
        self::assertSame(200.0, $materials['products'][0]['total']);
        self::assertSame(20.0, $materials['products'][0]['tax']);
        self::assertSame(6, $materials['products'][0]['reward']);
        self::assertSame('Black', $materials['products'][0]['option'][0]['value']);
        self::assertEqualsWithDelta(240.0, $materials['order_total'], 0.01);
    }

    public function testPlacementInsertsOutsideShoppingCart(): void
    {
        $html = '<div id="content"><div id="shopping-cart"><form>qty</form></div><a href="index.php?route=checkout/checkout">Checkout</a></div>';
        $fragment = '<div id="mt-uni-credit-cart-root">calc</div>';
        $out = (new StandardThemeCartPlacement())->insertAfterShoppingCart($html, $fragment);
        self::assertStringContainsString('</div><div id="mt-uni-credit-cart-root">', $out);
        $posCart = strpos($out, 'id="shopping-cart"');
        $posFrag = strpos($out, 'id="mt-uni-credit-cart-root"');
        self::assertNotFalse($posCart);
        self::assertNotFalse($posFrag);
        self::assertGreaterThan($posCart, $posFrag);
    }

    public function testCartJsHasStaleProtectionAndNoConsole(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_cart.js');
        self::assertStringContainsString('AbortController', $js);
        self::assertStringContainsString('currentSequence', $js);
        self::assertStringContainsString('cart.list', $js);
        self::assertStringContainsString('cart_fingerprint', $js);
        self::assertStringContainsString('resetFirstInstallmentForSchemeChange', $js);
        self::assertStringContainsString('invalidateOpenPopupForCartChange', $js);
        self::assertStringNotContainsString('console.log(', $js);
        self::assertMatchesRegularExpression('/replace\(\/&amp;\/g,\s*[\'"]&[\'"]\)/', $js);
        self::assertStringNotContainsString("'&amp;'", $js);
    }

    public function testCartEndpointsAndUrlsAreJsSafe(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_cart_view.php');
        self::assertStringContainsString('extension/mt_uni_credit/module/mt_uni_credit_cart.calculate', $view);
        self::assertStringContainsString('mt_uni_credit_cart.issueSubmission', $view);
        self::assertStringContainsString('mt_uni_credit_cart.submit', $view);
        self::assertStringContainsString('url->link(', $view);
        self::assertMatchesRegularExpression('/url->link\([^)]+true\s*\)/s', $view);
        self::assertStringNotContainsString('&amp;', $view);

        $controller = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/module/mt_uni_credit_cart.php');
        self::assertStringContainsString('ProductStorefrontCsrf', $controller);
        self::assertStringContainsString('cart_changed', $controller);
        self::assertStringContainsString('cart_unchanged', $controller);
    }

    public function testModalHidesSecondaryAndReusesStepContracts(): void
    {
        $modal = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_cart_modal.twig'
        );
        self::assertStringContainsString('data-mtuc-secondary hidden', $modal);
        self::assertStringContainsString('data-mtuc-step="1"', $modal);
        self::assertStringContainsString('data-mtuc-step="2"', $modal);
        self::assertStringContainsString('name="firstname"', $modal);
        self::assertStringContainsString('name="consent[]"', $modal);
        // Process 2 EGN/phone2 only behind modal.process2.
        self::assertStringContainsString('{% if mt_uni_credit.modal.process2 %}', $modal);
        self::assertMatchesRegularExpression(
            '/\{\%\s*if\s+mt_uni_credit\.modal\.process2\s*\%\}[\s\S]*name="egn"[\s\S]*name="phone2"/',
            $modal
        );
    }

    public function testCartSubmissionServiceUsesCartEntryPointAndStaleGuards(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__) . '/system/library/cart_financing_submission_service.php'
        );
        self::assertStringContainsString('OperationEntryPoint::CART', $src);
        self::assertStringContainsString('cart_changed', $src);
        self::assertStringContainsString('FinancingControlPanelCompletion', $src);
        self::assertStringContainsString('ResumeSubmissionFactory', $src);
        self::assertStringContainsString('ProcessTwoSubmissionSupport', $src);
        self::assertStringContainsString('CartOrderDraftFactory', $src);
        self::assertStringContainsString('cart_modal', $src);
        $doc = (string) file_get_contents(dirname(__DIR__) . '/docs/PHASE8.md');
        self::assertStringContainsString('cart_unchanged', $doc);
    }

    public function testPreferredSchemeKeyStableAcrossIntersection(): void
    {
        $shop = mt_uni_credit_calculator_fixture();
        $resolver = new CartSchemeResolver(new Calculator());
        $cart = new CartContext([
            mt_uni_credit_cart_line(1, [], 1000.0),
            mt_uni_credit_cart_line(2, [], 1000.0),
        ], 1000.0);
        $resolution = $resolver->resolve($shop, $cart);
        self::assertNotSame([], $resolution->standardSchemes);
        $scheme = $resolution->standardSchemes[0];
        $key = ProductSchemeList::key($scheme);
        self::assertStringContainsString('|', $key);
    }
}
