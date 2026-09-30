<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use MtUniCredit\Tests\Support\OrderMaterializationTestHarness;
use MtUniCredit\Tests\Support\ProductFinancingTestHarness;
use Opencart\System\Library\Extension\MtUniCredit\CartContext;
use Opencart\System\Library\Extension\MtUniCredit\CartLine;
use Opencart\System\Library\Extension\MtUniCredit\ControlPanelOrderPayloadBuilder;
use Opencart\System\Library\Extension\MtUniCredit\DurableEurOrderProof;
use Opencart\System\Library\Extension\MtUniCredit\EurFinancingAmount;
use Opencart\System\Library\Extension\MtUniCredit\OpenCartCatalogProductResolver;
use Opencart\System\Library\Extension\MtUniCredit\ProductContext;
use Opencart\System\Library\Extension\MtUniCredit\SmartUcfPayloadBuilder;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/fixtures/cp_shop_snapshot.php';

final class EurCurrencyUnitParityTest extends TestCase
{
    public function testProductUsesNativeConversionOnceAndCartConvertsBaseTotal(): void
    {
        $nativeFactor = 0.5; // deterministic test currency rate, not a production constant
        $converter = static function (float $amount, string $from, string $to) use ($nativeFactor): float {
            self::assertSame('BGN', $from);
            self::assertSame('EUR', $to);
            return $amount * $nativeFactor;
        };
        $resolver = new OpenCartCatalogProductResolver(
            1, 1, 'BGN', 'EUR', true,
            static fn(int $id): array => [
                'product_id' => $id, 'status' => 1, 'price' => 100.0,
                'discount' => 0.0, 'special' => 0.0, 'tax_class_id' => 0, 'name' => 'Test',
            ],
            static fn(int $id): array => [],
            static fn(int $id, int $quantity, array $options): array => [
                'option_price' => 0.0, 'order_options' => [], 'normalized' => [],
            ],
            static fn(float $price, int $taxClassId): float => $price,
            $converter
        );
        $product = $resolver->resolveLine(1, 42, 1, []);
        self::assertSame(50.0, $product->financingPrice);
        self::assertSame(100.0, $product->unitPriceExTax);

        $base = new CartContext([new CartLine(new ProductContext(42, [], 100.0), 0, 1, 100.0)], 100.0);
        $eur = (new \Opencart\System\Library\Extension\MtUniCredit\OpenCartCartContextFactory(
            static fn(int $id): array => [], static fn(float $price, int $tax): float => $price
        ))->create([['product_id' => 42, 'price' => 100.0, 'quantity' => 1]], 100.0, static fn(float $amount): float => $amount * $nativeFactor);
        self::assertSame(100.0, $base->total);
        self::assertSame(50.0, $eur->total);
        self::assertSame(50.0, $eur->lines[0]->product->price);
        self::assertSame(50.0, $eur->lines[0]->lineTotal);
    }

    public function testDirectBuildersRejectInconsistentDraftCurrency(): void
    {
        $submission = OrderMaterializationTestHarness::productSubmission();
        $submission->eurOrderProof = new DurableEurOrderProof(123, $submission->storeId, 1, 1.0, 1200.0, 1200.0);
        $submission->orderDraft->currencyCode = 'BGN';
        foreach ([
            static fn() => (new ControlPanelOrderPayloadBuilder())->build($submission, 123, ProductFinancingTestHarness::shop()),
            static fn() => (new SmartUcfPayloadBuilder())->build($submission, mt_uni_credit_valid_shop_snapshot(), 123),
        ] as $build) {
            try {
                $build();
                self::fail('Builder accepted a non-EUR draft.');
            } catch (\Opencart\System\Library\Extension\MtUniCredit\ProductFinancingFlowException $expected) {
                self::assertSame('currency_unavailable', $expected->errorCode());
            }
        }
    }

    public function testHistoricalOrderFactorControlsCpAndSmartUcfEuros(): void
    {
        $submission = OrderMaterializationTestHarness::productSubmission();
        $submission->financingCalculation = OrderMaterializationTestHarness::calculation(600.0);
        $submission->orderDraft->currencyValue = 0.5;
        $submission->eurOrderProof = new DurableEurOrderProof(123, $submission->storeId, 1, 0.5, 1200.0, 600.0);
        self::assertSame(1200.0, $submission->orderDraft->orderTotal);
        self::assertSame(600.0, EurFinancingAmount::fromOrder(1200.0, 'EUR', 1, 0.5));

        $cp = (new ControlPanelOrderPayloadBuilder())->build($submission, 123, ProductFinancingTestHarness::shop());
        self::assertSame('EUR', $cp['currency']);
        self::assertSame(600.0, $cp['price']);

        $bank = (new SmartUcfPayloadBuilder())->build($submission, mt_uni_credit_valid_shop_snapshot(), 123);
        self::assertSame('600.00', $bank['totalPrice']);
        $expectedUnit = (500.0 + 100.0) * 0.5; // independently specified native unit price + unit tax
        self::assertSame(number_format($expectedUnit, 2, '.', ''), $bank['items'][0]['singlePrice']);
    }
    public static function nativeItemCases(): array
    {
        return [
            'tax quantity two historical factor' => [500.0, 100.0, 2, 0.5, 0.0, 0.0, '300.00', '600.00'],
            'no tax' => [500.0, 0.0, 3, 0.5, 0.0, 0.0, '250.00', '750.00'],
            'convert before unit rounding' => [100.009, 0.0, 2, 0.5, 0.0, 0.0, '50.00', '100.01'],
            'tax fractional unit' => [100.0049, 20.001, 3, 10.0, 0.0, 0.0, '1200.06', '3600.18'],
            'shipping increases grand total' => [500.0, 100.0, 2, 0.5, 20.0, 0.0, '300.00', '610.00'],
            'discount reduces grand total' => [500.0, 100.0, 2, 0.5, 0.0, -100.0, '300.00', '550.00'],
        ];
    }

    #[DataProvider('nativeItemCases')]
    public function testNativeGrossItemsAndIndependentGrandTotal(float $price, float $tax, int $quantity, float $factor, float $shipping, float $discount, string $unit, string $grand): void
    {
        $submission = OrderMaterializationTestHarness::productSubmission();
        $baseTotal = ($price + $tax) * $quantity + $shipping + $discount;
        $eurTotal = round($baseTotal * $factor, 2);
        $submission->orderDraft->products = [['product_id' => 42, 'name' => 'Native item', 'quantity' => $quantity, 'price' => $price, 'total' => $price * $quantity, 'tax' => $tax]];
        $submission->orderDraft->orderTotal = $baseTotal;
        $submission->orderDraft->currencyValue = $factor;
        $submission->financingCalculation = OrderMaterializationTestHarness::calculation($eurTotal);
        $submission->eurOrderProof = new DurableEurOrderProof(123, $submission->storeId, 1, $factor, $baseTotal, $eurTotal);
        $payload = (new SmartUcfPayloadBuilder())->build($submission, mt_uni_credit_valid_shop_snapshot(), 123);
        self::assertSame($unit, $payload['items'][0]['singlePrice']);
        self::assertSame(number_format(($price + $tax) * $factor, 2, '.', ''), $payload['items'][0]['singlePrice']);
        self::assertSame($quantity, $payload['items'][0]['count']);
        self::assertSame($grand, $payload['totalPrice']);
        if ($shipping !== 0.0 || $discount !== 0.0) {
            self::assertNotEquals((float) $unit * $quantity, (float) $grand);
        }
    }
}
