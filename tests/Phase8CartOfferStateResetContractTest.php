<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression: Cart popup must not leak offer A calculation into offer B after close→reopen.
 * Product openModal already calls resetFirstInstallmentForSchemeChange(); Cart was missing that.
 */
final class Phase8CartOfferStateResetContractTest extends TestCase
{
    private function cartJs(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_cart.js');
    }

    private function productJs(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
    }

    public function testProductOpenModalResetsBeforeRecalculate(): void
    {
        $js = $this->productJs();
        self::assertMatchesRegularExpression(
            '/async function openModal\([\s\S]*?resetFirstInstallmentForSchemeChange\(\);[\s\S]*?await recalculateSelection\(\)/',
            $js
        );
    }

    public function testCartOpenModalResetsOfferStateBeforeRecalculate(): void
    {
        $js = $this->cartJs();
        self::assertStringContainsString('function resetCartModalOfferState()', $js);
        self::assertMatchesRegularExpression(
            '/async function openModal\([\s\S]*?resetCartModalOfferState\(\);[\s\S]*?await recalculateSelection\(\)/',
            $js
        );
        self::assertMatchesRegularExpression(
            '/async function openModal\([\s\S]*?setProcessing\(true\)[\s\S]*?await recalculateSelection\(\)/',
            $js
        );
    }

    public function testCartResetClearsTokenCalculationFirstInstallmentAndDisplays(): void
    {
        $js = $this->cartJs();
        $body = $this->extractFunctionBody($js, 'resetCartModalOfferState');

        self::assertStringContainsString("lastCalculation = null", $body);
        self::assertMatchesRegularExpression('/submissionToken\s*=\s*(?:\'\'|"")/', $body);
        self::assertMatchesRegularExpression('/first\.value\s*=\s*[\'"]0[\'"]/', $body);
        self::assertMatchesRegularExpression('/removeAttribute\([\'"]readonly[\'"]\)/', $body);
        self::assertStringContainsString('clearCalculationDisplays()', $body);
        self::assertStringContainsString('[data-mtuc-display]', $this->extractFunctionBody($js, 'clearCalculationDisplays'));
    }

    public function testSchemeChangeUsesSameCanonicalReset(): void
    {
        $js = $this->cartJs();
        self::assertMatchesRegularExpression(
            '/function resetFirstInstallmentForSchemeChange\(\)\s*\{\s*resetCartModalOfferState\(\);/',
            $js
        );
        self::assertMatchesRegularExpression(
            '/schemeSelect\(\)\?\.addEventListener\([\'"]change[\'"][\s\S]*?resetFirstInstallmentForSchemeChange\(\);[\s\S]*?recalculateSelection\(\)/',
            $js
        );
    }

    public function testOfferClickSetsFreshIdentityWithoutPreviousPreferredKey(): void
    {
        $js = $this->cartJs();
        self::assertStringContainsString('selectedOfferType = trigger.dataset.offerType', $js);
        self::assertMatchesRegularExpression(
            '/selectedSchemeKey\s*=\s*trigger\.dataset\.preferredKey\s*\|\|\s*(?:\'\'|"")/',
            $js
        );
        self::assertStringNotContainsString(
            'selectedSchemeKey = trigger.dataset.preferredKey || selectedSchemeKey',
            $js
        );
    }

    public function testCloseModalAlsoClearsOfferFinancingState(): void
    {
        $js = $this->cartJs();
        self::assertMatchesRegularExpression(
            '/function closeModal\(\)\s*\{[\s\S]*?resetCartModalOfferState\(\);/',
            $js
        );
    }

    public function testStep2CannotReuseStaleCalculationContract(): void
    {
        $js = $this->cartJs();
        self::assertStringContainsString('!lastCalculation', $js);
        self::assertStringContainsString('hasAuthoritativeCalculation', $js);
        self::assertMatchesRegularExpression(
            '/function hasAuthoritativeCalculation\(\)\s*\{[\s\S]*?lastCalculation[\s\S]*?submissionToken/',
            $js
        );
    }

    public function testNoConsoleDiagnostics(): void
    {
        $js = $this->cartJs();
        foreach (['console.log(', 'console.info(', 'console.debug(', 'console.warn(', 'console.error('] as $call) {
            self::assertStringNotContainsString($call, $js);
        }
    }

    private function extractFunctionBody(string $source, string $name): string
    {
        if (!preg_match('/function ' . preg_quote($name, '/') . '\([^)]*\)\s*\{/', $source, $m, PREG_OFFSET_CAPTURE)) {
            self::fail('Function not found: ' . $name);
        }
        $start = (int) $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $len = strlen($source);
        for ($i = $start; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }
            }
        }
        self::fail('Unbalanced braces for ' . $name);

        return '';
    }
}
