<?php

declare(strict_types=1);

namespace MtUniCredit\Tests\Support;

use MtUniCredit\Tests\Support\FixtureLoader;
use Opencart\System\Library\Extension\MtUniCredit\Calculator;
use Opencart\System\Library\Extension\MtUniCredit\CartSchemeResolver;
use Opencart\System\Library\Extension\MtUniCredit\ProductContext;

require_once dirname(__DIR__) . '/fixtures/calculator_fixture.php';

final class CalculatorTestHarness
{
    public const FIXED_TODAY = '2026-08-17';

    public static function calculator(): Calculator
    {
        return new Calculator(self::FIXED_TODAY);
    }

    public static function cartResolver(): CartSchemeResolver
    {
        return new CartSchemeResolver(self::calculator());
    }

    /** @return array<string, mixed> */
    public static function defaultShop(array $overrides = []): array
    {
        return mt_uni_credit_calculator_fixture($overrides);
    }

    /** @return array<string, mixed> */
    public static function schemaShop(array $overrides = []): array
    {
        $shop = mt_uni_credit_calculator_fixture([
            'uni_typekop' => 1,
            'kop'         => ['by_schema' => ['filters' => mt_uni_credit_schema_filters_fixture()]],
        ]);
        if (isset($overrides['kop']['by_schema']['filters'])) {
            $shop['kop']['by_schema']['filters'] = $overrides['kop']['by_schema']['filters'];
            unset($overrides['kop']);
        }

        return $overrides === [] ? $shop : array_replace_recursive($shop, $overrides);
    }

    public static function defaultProduct(float $price = 1000.0): ProductContext
    {
        return new ProductContext(42, [7, 9], $price);
    }

    /** @return array<string, mixed> */
    public static function goldenFixture(): array
    {
        return FixtureLoader::load('calculator_golden.json');
    }
}
