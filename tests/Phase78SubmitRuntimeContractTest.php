<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Product/Cart Step 2 Изпрати must actually invoke submit_url (no silent early exit / aborted fetch).
 */
final class Phase78SubmitRuntimeContractTest extends TestCase
{
    private function productJs(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
    }

    private function cartJs(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_cart.js');
    }

    public function testProductSubmitClickHandlerInvokesSubmitForm(): void
    {
        $js = $this->productJs();
        self::assertMatchesRegularExpression(
            '/closest\(\'\\[data-mtuc-submit\\]\'\)[\s\S]*?submitForm\(/',
            $js
        );
        self::assertStringContainsString("form?.addEventListener('submit', submitForm)", $js);
    }

    public function testProductSubmitPostsToSubmitUrlWithoutRefreshAbort(): void
    {
        $js = $this->productJs();
        self::assertStringContainsString('postJson(state.submit_url, payload, { abort: false })', $js);
        self::assertStringContainsString('opts.abort !== false', $js);
    }

    public function testProductSubmitSurfacesGuardFailures(): void
    {
        $js = $this->productJs();
        self::assertStringContainsString('Моля, изберете схема и изчакайте изчислението преди изпращане.', $js);
        self::assertStringContainsString('activeForm', $js);
    }

    public function testProductLockedFirstInstallmentNotDisabled(): void
    {
        $js = $this->productJs();
        self::assertStringContainsString("first.setAttribute('readonly', 'readonly')", $js);
        self::assertDoesNotMatchRegularExpression(
            '/first_installment_locked[\s\S]{0,200}setAttribute\(\'disabled\'/',
            $js
        );
    }

    public function testCartSubmitMirrorsProductAbortIsolation(): void
    {
        $js = $this->cartJs();
        self::assertStringContainsString('postJson(state.submit_url, payload, { abort: false })', $js);
        self::assertMatchesRegularExpression(
            '/closest\(\'\\[data-mtuc-submit\\]\'\)[\s\S]*?submitForm\(/',
            $js
        );
    }

    public function testNoConsoleDiagnosticsInSubmitPaths(): void
    {
        foreach ([$this->productJs(), $this->cartJs()] as $js) {
            foreach (['console.log(', 'console.info(', 'console.debug(', 'console.warn(', 'console.error('] as $call) {
                self::assertStringNotContainsString($call, $js);
            }
        }
    }
}
