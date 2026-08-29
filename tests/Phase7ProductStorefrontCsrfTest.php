<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\ProductStorefrontCsrf;
use PHPUnit\Framework\TestCase;

final class Phase7ProductStorefrontCsrfTest extends TestCase
{
    public function testGetOrCreateIssuesCryptographicTokenForGuestSession(): void
    {
        $csrf = new ProductStorefrontCsrf();
        $session = [];
        $token = $csrf->getOrCreate($session);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame($token, $session[ProductStorefrontCsrf::SESSION_KEY]);
        self::assertSame('ok', $csrf->validate($session, $token));
    }

    public function testGetOrCreateReusesExistingSessionToken(): void
    {
        $csrf = new ProductStorefrontCsrf();
        $session = [];
        $first = $csrf->getOrCreate($session);
        $second = $csrf->getOrCreate($session);

        self::assertSame($first, $second);
    }

    public function testMissingTokenFails(): void
    {
        $csrf = new ProductStorefrontCsrf();
        $session = [];
        $csrf->getOrCreate($session);

        self::assertSame('missing_csrf', $csrf->validate($session, ''));
        self::assertSame('missing_csrf', $csrf->validate([], 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
    }

    public function testWrongTokenFails(): void
    {
        $csrf = new ProductStorefrontCsrf();
        $session = [];
        $csrf->getOrCreate($session);

        self::assertSame(
            'invalid_csrf',
            $csrf->validate($session, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')
        );
    }

    public function testLoggedInSessionUsesSameContract(): void
    {
        $csrf = new ProductStorefrontCsrf();
        $session = ['customer_id' => 42];
        $token = $csrf->getOrCreate($session);

        self::assertSame('ok', $csrf->validate($session, $token));
        self::assertSame(42, $session['customer_id']);
    }

    public function testControllerUsesModuleCsrfNotOpencartCsrfKey(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/module/mt_uni_credit_product.php'
        );
        $view = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_product_view.php'
        );

        self::assertStringContainsString('ProductStorefrontCsrf', $controller);
        self::assertStringContainsString('ProductStorefrontCsrf', $view);
        self::assertStringContainsString('missing_csrf', $controller);
        self::assertStringContainsString('invalid_csrf', $controller);
        self::assertStringNotContainsString("session->data['csrf_token']", $controller);
        self::assertStringNotContainsString("session->data['csrf_token']", $view);
        self::assertStringContainsString('mt_uni_credit_csrf_token', (string) file_get_contents(
            dirname(__DIR__) . '/system/library/product_storefront_csrf.php'
        ));
    }

    public function testProductAjaxUrlsUseJsSafeAmpersand(): void
    {
        $view = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/controller/event/mt_uni_credit_product_view.php'
        );

        self::assertStringContainsString("mt_uni_credit_product.calculate", $view);
        self::assertStringContainsString("mt_uni_credit_product.issueSubmission", $view);
        self::assertStringContainsString("mt_uni_credit_product.submit", $view);
        // Third argument true => Url::link keeps raw "&" for JavaScript.
        self::assertSame(3, substr_count($view, "'language=' . \$language,\n            true"));
        self::assertStringContainsString("url->link('checkout/checkout', 'language=' . \$language, true)", $view);
        self::assertStringContainsString('mt_uni_credit_bootstrap_json', $view);
        self::assertStringContainsString('JSON_UNESCAPED_SLASHES', $view);
        self::assertStringContainsString('keeps raw "&"', $view);
    }

    public function testBootstrapTwigUsesPhpEncodedJsonRaw(): void
    {
        $twig = (string) file_get_contents(
            dirname(__DIR__) . '/catalog/view/template/module/mt_uni_credit_product_calculator.twig'
        );

        self::assertStringContainsString('mt_uni_credit_bootstrap_json|raw', $twig);
        self::assertStringNotContainsString('|json_encode|raw', $twig);
    }

    public function testJsSafeUrlEncodingContract(): void
    {
        // Mirrors OpenCart Url::link($route, $args, true).
        $htmlUrl = 'https://open40.avalonbg.com/index.php?route=extension/mt_uni_credit/module/mt_uni_credit_product.calculate&amp;language=bg-bg';
        $jsUrl = str_replace('&amp;', '&', $htmlUrl);

        self::assertStringContainsString('&language=', $jsUrl);
        self::assertStringNotContainsString('&amp;', $jsUrl);
        self::assertStringContainsString('&amp;', $htmlUrl);
    }
}
