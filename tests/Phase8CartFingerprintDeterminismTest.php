<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\CartFingerprint;
use Opencart\System\Library\Extension\MtUniCredit\CartLine;
use Opencart\System\Library\Extension\MtUniCredit\CartContext;
use Opencart\System\Library\Extension\MtUniCredit\CartSelectionHash;
use Opencart\System\Library\Extension\MtUniCredit\ProductContext;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic Cart fingerprint + selection-hash contract (false cart_changed / stale_selection).
 */
final class Phase8CartFingerprintDeterminismTest extends TestCase
{
    public function testSameCartBuiltTwiceYieldsSameFingerprint(): void
    {
        $a = $this->cart([[1, [9, 7], 100.0, 2, [5, 3]]], 200.0);
        $b = $this->cart([[1, [9, 7], 100.0, 2, [5, 3]]], 200.0);
        self::assertSame(CartFingerprint::hash($a, 'BGN'), CartFingerprint::hash($b, 'bgn'));
    }

    public function testCategoryAndOptionOrderDoNotChangeFingerprint(): void
    {
        $ordered = $this->cart([[1, [7, 9], 100.0, 1, [3, 5]]], 100.0);
        $shuffled = $this->cart([[1, [9, 7], 100.0, 1, [5, 3]]], 100.0);
        self::assertSame(CartFingerprint::hash($ordered, 'BGN'), CartFingerprint::hash($shuffled, 'BGN'));
    }

    public function testEquivalentMonetaryRepresentationYieldsSameFingerprint(): void
    {
        $a = $this->cart([[1, [7], 99.996, 1, []]], 99.996);
        $b = $this->cart([[1, [7], 99.995999999, 1, []]], 99.995999999);
        self::assertSame(CartFingerprint::hash($a, 'BGN'), CartFingerprint::hash($b, 'BGN'));
    }

    public function testQuantityChangeChangesFingerprint(): void
    {
        $a = $this->cart([[1, [7], 100.0, 1, []]], 100.0);
        $b = $this->cart([[1, [7], 100.0, 2, []]], 200.0);
        self::assertNotSame(CartFingerprint::hash($a, 'BGN'), CartFingerprint::hash($b, 'BGN'));
    }

    public function testProductRemoveChangesFingerprint(): void
    {
        $a = $this->cart([[1, [7], 100.0, 1, []], [2, [7], 50.0, 1, []]], 150.0);
        $b = $this->cart([[1, [7], 100.0, 1, []]], 100.0);
        self::assertNotSame(CartFingerprint::hash($a, 'BGN'), CartFingerprint::hash($b, 'BGN'));
    }

    public function testOptionChangeChangesFingerprint(): void
    {
        $a = $this->cart([[1, [7], 100.0, 1, [10]]], 100.0);
        $b = $this->cart([[1, [7], 100.0, 1, [11]]], 100.0);
        self::assertNotSame(CartFingerprint::hash($a, 'BGN'), CartFingerprint::hash($b, 'BGN'));
    }

    public function testAuthoritativeTotalChangeChangesFingerprint(): void
    {
        $a = $this->cart([[1, [7], 100.0, 1, []]], 100.0);
        $b = $this->cart([[1, [7], 100.0, 1, []]], 90.0);
        self::assertNotSame(CartFingerprint::hash($a, 'BGN'), CartFingerprint::hash($b, 'BGN'));
    }

    public function testSelectionHashUsesNormalizedFirstInstallment(): void
    {
        $fp = hash('sha256', 'fp');
        $actor = hash('sha256', 'actor');
        $a = CartSelectionHash::hash(0, $fp, 'BGN', 500.0, 'k', 'standard', 'STD', 12, 1, 41.67, $actor);
        $b = CartSelectionHash::hash(0, $fp, 'BGN', 500.0, 'k', 'standard', 'STD', 12, 1, 41.6700001, $actor);
        self::assertSame($a, $b);
    }

    public function testControllersHashAuthoritativeCalculatedFirstInstallment(): void
    {
        $product = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/module/mt_uni_credit_product.php');
        $cart = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/module/mt_uni_credit_cart.php');
        self::assertStringContainsString("\$calculation['first_installment']", $product);
        self::assertStringContainsString("\$calculation['first_installment']", $cart);
        self::assertMatchesRegularExpression(
            '/\$firstInstallment = \(float\) \(\$calculation\[[\'"]first_installment[\'"]\]/',
            $product
        );
        self::assertMatchesRegularExpression(
            '/\$firstInstallment = \(float\) \(\$calculation\[[\'"]first_installment[\'"]\]/',
            $cart
        );
    }

    /**
     * @param list<array{0:int,1:list<int>,2:float,3:int,4:list<int>}> $rows
     */
    private function cart(array $rows, float $total): CartContext
    {
        $lines = [];
        foreach ($rows as $row) {
            [$productId, $categories, $unitOrLine, $qty, $options] = $row;
            $lineTotal = round($unitOrLine * $qty, 2);
            $attr = $options === [] ? 0 : max($options);
            $lines[] = new CartLine(
                new ProductContext($productId, $categories, $lineTotal),
                $attr,
                $qty,
                $lineTotal,
                $options
            );
        }

        return new CartContext($lines, $total);
    }
}
