<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Product Step 2 must not stay submit-ready after calculator invalidation;
 * submit recovers/re-issues before POST when context was cleared.
 */
final class Phase78ProductStep2LifecycleContractTest extends TestCase
{
    private function productJs(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
    }

    public function testAuthoritativeReadinessRequiresCalculationTokenAndScheme(): void
    {
        $js = $this->productJs();
        self::assertMatchesRegularExpression(
            '/function hasAuthoritativeCalculation\(\)\s*\{[\s\S]*?lastCalculation\s*&&\s*submissionToken\s*&&\s*selectedScheme\(\)/',
            $js
        );
    }

    public function testRenderCalculatorInvalidatesIssuedSelection(): void
    {
        $js = $this->productJs();
        self::assertStringContainsString('function invalidateIssuedSelection(', $js);
        self::assertMatchesRegularExpression(
            '/function renderCalculator\([\s\S]*?invalidateIssuedSelection\(/',
            $js
        );
        self::assertMatchesRegularExpression(
            '/function invalidateIssuedSelection\([\s\S]*?submissionToken = \'\'[\s\S]*?lastCalculation = null/',
            $js
        );
        self::assertMatchesRegularExpression(
            '/function invalidateIssuedSelection\([\s\S]*?classList\.add\(\'is-disabled\'\)/',
            $js
        );
    }

    public function testStep2RefreshReIssuesSelection(): void
    {
        $js = $this->productJs();
        self::assertMatchesRegularExpression(
            '/async function refreshCalculator\([\s\S]*?renderCalculator\([\s\S]*?populateSchemeSelect\(\);[\s\S]*?await recalculateSelection\(\)/',
            $js
        );
        self::assertMatchesRegularExpression(
            '/currentStep === 2[\s\S]*?setProcessing\(true\)[\s\S]*?await recalculateSelection\(\)/',
            $js
        );
    }

    public function testSubmitRecoversMissingContextThenPosts(): void
    {
        $js = $this->productJs();
        self::assertMatchesRegularExpression(
            '/async function submitForm\([\s\S]*?syncSelectedSchemeFromDom\(\)[\s\S]*?await recalculateSelection\(\)[\s\S]*?postJson\(state\.submit_url/',
            $js
        );
        self::assertStringContainsString('!lastCalculation || !submissionToken', $js);
        self::assertStringContainsString("closest('[data-mtuc-submit]')", $js);
    }

    public function testApplyRequiresAuthoritativeContext(): void
    {
        $js = $this->productJs();
        self::assertMatchesRegularExpression(
            '/data-mtuc-apply[\s\S]*?!apply\.disabled && hasAuthoritativeCalculation\(\)/',
            $js
        );
    }
}
