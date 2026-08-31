<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Product Step 2 submit must recover authoritative context even when calcBusy / refresh races.
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

    public function testRecalculateSelectionReturnsExplicitBoolean(): void
    {
        $js = $this->productJs();
        self::assertStringContainsString('@returns {Promise<boolean>}', $js);
        self::assertMatchesRegularExpression(
            '/async function recalculateSelection\([\s\S]*?return ok;/',
            $js
        );
        self::assertStringContainsString('issueFlight', $js);
        // Must not silently return on calcBusy alone.
        self::assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*!scheme\s*\|\|\s*calcBusy\s*\)\s*\{\s*return;?\s*\}/',
            $js
        );
    }

    public function testIssueAssignsSubmissionTokenFromResponseKey(): void
    {
        $js = $this->productJs();
        self::assertStringContainsString('json.submission_token', $js);
        self::assertMatchesRegularExpression(
            '/const token = String\(json\.submission_token \|\| (?:\'\'|"")\);[\s\S]*?submissionToken = token;/',
            $js
        );
    }

    public function testSubmitRecoveryForcesIssueWithoutAbort(): void
    {
        $js = $this->productJs();
        self::assertMatchesRegularExpression(
            '/recalculateSelection\(\{[\s\S]*?force:\s*true,[\s\S]*?abort:\s*false[\s\S]*?\}\)/',
            $js
        );
        self::assertMatchesRegularExpression(
            '/const recovered = await recalculateSelection\(\{[\s\S]*?force:\s*true,[\s\S]*?abort:\s*false[\s\S]*?\}\);[\s\S]*?if\s*\(\s*!recovered\s*\)/',
            $js
        );
        self::assertMatchesRegularExpression(
            '/postJson\(\s*state\.submit_url,\s*payload,\s*\{[\s\S]*?abort:\s*false/',
            $js
        );
    }

    public function testPopupControlsDoNotScheduleProductRefresh(): void
    {
        $js = $this->productJs();
        self::assertStringContainsString('function isInsideUniCreditUi(', $js);
        self::assertMatchesRegularExpression(
            '/document\.addEventListener\([\'"]change[\'"][\s\S]*?isInsideUniCreditUi\(target\)[\s\S]*?return;/',
            $js
        );
    }

    public function testRenderCalculatorInvalidatesIssuedSelection(): void
    {
        $js = $this->productJs();
        self::assertMatchesRegularExpression(
            '/function renderCalculator\([\s\S]*?invalidateIssuedSelection\(/',
            $js
        );
    }

    public function testStep2RefreshReIssuesWithForce(): void
    {
        $js = $this->productJs();
        self::assertMatchesRegularExpression(
            '/async function refreshCalculator\([\s\S]*?await recalculateSelection\(\{ force: true \}\)/',
            $js
        );
    }

    public function testApplyRequiresAuthoritativeContext(): void
    {
        $js = $this->productJs();
        self::assertMatchesRegularExpression(
            '/data-mtuc-apply[\s\S]*?!apply\.disabled && hasAuthoritativeCalculation\(\)/',
            $js
        );
    }

    public function testActiveFormResolvedFromModalAtSubmit(): void
    {
        $js = $this->productJs();
        self::assertStringContainsString('function activeProductFinancingForm(', $js);
        self::assertStringContainsString('const activeForm = activeProductFinancingForm()', $js);
    }
}
