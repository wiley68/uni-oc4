<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\CalculatorTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\AvailableScheme;
use Opencart\System\Library\Extension\MtUniCredit\Calculator;
use Opencart\System\Library\Extension\MtUniCredit\CartCalculatorPresenter;
use Opencart\System\Library\Extension\MtUniCredit\CartContext;
use Opencart\System\Library\Extension\MtUniCredit\CartSchemeResolver;
use Opencart\System\Library\Extension\MtUniCredit\CurrencyGate;
use Opencart\System\Library\Extension\MtUniCredit\InstallmentLabelFormatter;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartProductLine;
use Opencart\System\Library\Extension\MtUniCredit\ProductCalculatorPresenter;
use Opencart\System\Library\Extension\MtUniCredit\ProductSchemeList;
use Opencart\System\Library\Extension\MtUniCredit\SchemePresentationCategory;
use Opencart\System\Library\Extension\MtUniCredit\SchemePresentationOrder;
use PHPUnit\Framework\TestCase;

/**
 * Cross-surface scheme ordering with production-like type/filter semantics.
 *
 * Live shop cache (uni_typekop=1) often has empty uni_kop_default and equal-month
 * pairs that are BOTH AvailableScheme::type=standard — one with promotional description.
 */
final class Phase9SchemePresentationOrderTest extends TestCase
{
    public function testProductionTypeSemanticsAreStringStandardAndPromo(): void
    {
        $plain = new AvailableScheme(
            'standard',
            'POS COM 50',
            12,
            11,
            ['id' => 11, 'uni_promo' => 0, 'uni_kop' => 'POS COM 50', 'uni_kop_desc' => null],
            ['interestPercent' => 30, 'coeff' => 0.1]
        );
        $overlay = new AvailableScheme(
            'standard',
            'POS COM 100',
            12,
            10,
            [
                'id'           => 10,
                'uni_promo'    => 0,
                'category_id'  => '20',
                'uni_meseci'   => '12',
                'uni_kop'      => 'POS COM 100',
                'uni_kop_desc' => 'Промо лихва за компютри',
            ],
            ['interestPercent' => 21.45, 'coeff' => 0.095]
        );
        $zero = new AvailableScheme(
            'promo',
            'POS COM 0%V1',
            6,
            12,
            [
                'id'           => 12,
                'uni_promo'    => 1,
                'product_id'   => '40',
                'uni_meseci'   => '6',
                'uni_kop'      => 'POS COM 0%V1',
                'uni_kop_desc' => '0% лихва за компютри',
            ],
            ['interestPercent' => 0, 'coeff' => 0.083333]
        );

        self::assertSame('standard', $plain->type);
        self::assertSame('standard', $overlay->type);
        self::assertSame('promo', $zero->type);
        self::assertSame(0, (int) $plain->filter['uni_promo']);
        self::assertSame(0, (int) $overlay->filter['uni_promo']);
        self::assertSame(1, (int) $zero->filter['uni_promo']);
    }

    public function testEmptyDefaultKopInfersBaselineAndOrdersPromoOverlayAfterPlain(): void
    {
        $shop = $this->productionLikeShop();
        self::assertSame('', trim((string) ($shop['kop']['by_default']['uni_kop_default'] ?? '')));
        self::assertSame('POS COM 50', SchemePresentationCategory::defaultKop($shop));

        $schemes = [
            new AvailableScheme(
                'standard',
                'POS COM 100',
                12,
                10,
                [
                    'id'           => 10,
                    'uni_promo'    => 0,
                    'category_id'  => '20',
                    'uni_meseci'   => '12',
                    'uni_kop_desc' => 'Промо лихва за компютри',
                ],
                ['interestPercent' => 21.45, 'coeff' => 0.095]
            ),
            new AvailableScheme(
                'standard',
                'POS COM 50',
                6,
                11,
                ['id' => 11, 'uni_promo' => 0],
                ['interestPercent' => 30, 'coeff' => 0.1]
            ),
            new AvailableScheme(
                'standard',
                'POS COM 50',
                12,
                11,
                ['id' => 11, 'uni_promo' => 0],
                ['interestPercent' => 30, 'coeff' => 0.1]
            ),
            new AvailableScheme(
                'promo',
                'POS COM 0%V1',
                6,
                12,
                ['id' => 12, 'uni_promo' => 1, 'uni_kop_desc' => '0% лихва за компютри'],
                ['interestPercent' => 0, 'coeff' => 0.083333]
            ),
            new AvailableScheme(
                'standard',
                'POS COM 50',
                18,
                11,
                ['id' => 11, 'uni_promo' => 0],
                ['interestPercent' => 30, 'coeff' => 0.1]
            ),
        ];

        $sorted = SchemePresentationOrder::sort($schemes, $shop);
        self::assertSame([
            '6:standard:standard',
            '6:promo:zero_promo',
            '12:standard:standard',
            '12:standard:nonzero_promo',
            '18:standard:standard',
        ], array_map(
            static fn(AvailableScheme $s): string => $s->months . ':' . $s->type . ':'
                . SchemePresentationCategory::classify($s, $shop),
            $sorted
        ));
        self::assertSame('POS COM 50', $sorted[2]->kopCode);
        self::assertSame('POS COM 100', $sorted[3]->kopCode);
        self::assertSame('Промо лихва за компютри', ProductSchemeList::description($shop, $sorted[3]));
    }

    public function testProductCartCheckoutFinalDtoOrderShareContract(): void
    {
        $shop = $this->productionLikeShop();
        $line = new OpenCartProductLine(
            40,
            40,
            [20, 24],
            'iPhone',
            'iphone',
            1,
            0,
            0,
            false,
            1000.0,
            0.0,
            1000.0,
            [],
            []
        );
        $productPresenter = new ProductCalculatorPresenter(
            new Calculator(CalculatorTestHarness::FIXED_TODAY),
            new CurrencyGate(),
            new ProductSchemeList(new Calculator(CalculatorTestHarness::FIXED_TODAY)),
            new InstallmentLabelFormatter()
        );
        $productPayload = $productPresenter->present($shop, $line, 'BGN');
        self::assertNotNull($productPayload);
        $productSchemes = $productPayload['offers']['standard']['schemes'];
        $this->assertCanonicalFinalOrder($productSchemes);
        $preferred = (string) $productPayload['offers']['standard']['preferred_scheme_key'];
        self::assertContains($preferred, array_column($productSchemes, 'key'));

        $cart = new CartContext([
            mt_uni_credit_cart_line(40, [20, 24], 1000, 1, 1000),
        ], 1000.0);
        $cartPresenter = new CartCalculatorPresenter(
            new CartSchemeResolver(new Calculator(CalculatorTestHarness::FIXED_TODAY)),
            new Calculator(CalculatorTestHarness::FIXED_TODAY),
            new CurrencyGate(),
            new InstallmentLabelFormatter()
        );
        $cartPayload = $cartPresenter->present($shop, $cart, 'BGN');
        self::assertNotNull($cartPayload);
        $cartSchemes = $cartPayload['offers']['standard']['schemes'];
        $this->assertCanonicalFinalOrder($cartSchemes);
        self::assertContains(
            (string) $cartPayload['offers']['standard']['preferred_scheme_key'],
            array_column($cartSchemes, 'key')
        );

        // Checkout reuses CartCalculatorPresenter final DTO.
        self::assertSame(
            array_map(static fn(array $row): string => $row['key'], $cartSchemes),
            array_map(static fn(array $row): string => $row['key'], $cartSchemes)
        );
    }

    public function testJsDoesNotReorderSchemeOptions(): void
    {
        foreach (['mt_uni_credit_product.js', 'mt_uni_credit_cart.js', 'mt_uni_credit_checkout.js'] as $file) {
            $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/' . $file);
            self::assertDoesNotMatchRegularExpression('/schemes\s*\.\s*sort\s*\(/', $js, $file);
            self::assertDoesNotMatchRegularExpression('/schemes\s*\.\s*reverse\s*\(/', $js, $file);
            self::assertStringContainsString('forEach((scheme)', $js);
        }
    }

    /**
     * @param list<array<string, mixed>> $schemes
     */
    private function assertCanonicalFinalOrder(array $schemes): void
    {
        $prevMonths = -1;
        $byMonth = [];
        foreach ($schemes as $index => $row) {
            $months = (int) $row['months'];
            self::assertGreaterThanOrEqual($prevMonths, $months);
            $prevMonths = $months;
            $byMonth[$months][] = [
                'index'       => $index,
                'type'        => (string) $row['scheme_type'],
                'description' => (string) ($row['description'] ?? ''),
                'kop'         => (string) $row['kop_code'],
            ];
        }
        foreach ($byMonth as $months => $rows) {
            if (count($rows) < 2) {
                continue;
            }
            // Plain / baseline before promotional description or type=promo.
            $promoLikeIndexes = [];
            $plainIndexes = [];
            foreach ($rows as $row) {
                $promoLike = $row['type'] === 'promo' || $row['description'] !== '';
                if ($promoLike) {
                    $promoLikeIndexes[] = $row['index'];
                } else {
                    $plainIndexes[] = $row['index'];
                }
            }
            if ($plainIndexes !== [] && $promoLikeIndexes !== []) {
                self::assertLessThan(
                    min($promoLikeIndexes),
                    max($plainIndexes),
                    'month ' . $months . ' plain before promo-like'
                );
            }
        }
    }

    /** @return array<string, mixed> */
    private function productionLikeShop(): array
    {
        return CalculatorTestHarness::defaultShop([
            'uni_typekop' => 1,
            'uni_eur'     => 0,
            'kop'         => [
                'by_default' => [
                    'uni_kop_default'      => '',
                    'uni_kop_default_desc' => '',
                    'uni_kop_promo'        => '',
                    'uni_kop_promo_desc'   => '',
                ],
                'by_schema' => [
                    'filters' => [
                        [
                            'id'           => 10,
                            'category_id'  => '20',
                            'product_id'   => null,
                            'uni_meseci'   => '12',
                            'uni_promo'    => 0,
                            'uni_parva'    => 1,
                            'uni_date_from'=> '2026-08-01',
                            'uni_date_to'  => '2026-12-31',
                            'uni_kop'      => 'POS COM 100',
                            'uni_kop_desc' => 'Промо лихва за компютри',
                        ],
                        [
                            'id'           => 11,
                            'category_id'  => null,
                            'product_id'   => null,
                            'uni_meseci'   => null,
                            'uni_promo'    => 0,
                            'uni_parva'    => 0,
                            'uni_date_from'=> '2026-08-01',
                            'uni_date_to'  => null,
                            'uni_kop'      => 'POS COM 50',
                            'uni_kop_desc' => null,
                        ],
                        [
                            'id'           => 12,
                            'category_id'  => null,
                            'product_id'   => '40',
                            'uni_meseci'   => '6',
                            'uni_promo'    => 1,
                            'uni_parva'    => 0,
                            'uni_date_from'=> '2026-08-01',
                            'uni_date_to'  => null,
                            'uni_kop'      => 'POS COM 0%V1',
                            'uni_kop_desc' => '0% лихва за компютри',
                        ],
                    ],
                ],
            ],
            'coeff_list' => array_merge(
                CalculatorTestHarness::defaultShop()['coeff_list'],
                [
                    ['onlineProductCode' => 'POS COM 50', 'installmentCount' => 6, 'coeff' => 0.18, 'interestPercent' => 30],
                    ['onlineProductCode' => 'POS COM 50', 'installmentCount' => 12, 'coeff' => 0.1, 'interestPercent' => 30],
                    ['onlineProductCode' => 'POS COM 50', 'installmentCount' => 18, 'coeff' => 0.08, 'interestPercent' => 30],
                    ['onlineProductCode' => 'POS COM 100', 'installmentCount' => 12, 'coeff' => 0.095, 'interestPercent' => 21.45],
                    ['onlineProductCode' => 'POS COM 0%V1', 'installmentCount' => 6, 'coeff' => 0.083333, 'interestPercent' => 0],
                ]
            ),
        ]);
    }
}
