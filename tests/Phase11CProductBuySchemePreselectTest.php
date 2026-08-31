<?php

declare(strict_types=1);

namespace MtUniCredit\Tests;

use Opencart\System\Library\Extension\MtUniCredit\FinancingSchemeIdentity;
use Opencart\System\Library\Extension\MtUniCredit\ModuleConstants;
use Opencart\System\Library\Extension\MtUniCredit\ProductBuyCheckoutPreference;
use Opencart\System\Library\Extension\MtUniCredit\ProductSchemeList;
use PHPUnit\Framework\TestCase;

/**
 * Phase 11C Remediation 09A — exact Product Buy scheme preselection in Checkout.
 */
final class Phase11CProductBuySchemePreselectTest extends TestCase
{
    public function testVersionFrozen(): void
    {
        self::assertSame('2.0.2', ModuleConstants::VERSION);
    }

    public function testStashPersistsNonDefaultSchemeKeyNotPreferredOfferDefault(): void
    {
        $session = [];
        $defaultKey = ProductSchemeList::keyFromParts('standard', 'KOP', 6, 1);
        $selectedKey = ProductSchemeList::keyFromParts('standard', 'KOP', 12, 3);
        self::assertNotSame($defaultKey, $selectedKey);

        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 40,
            'scheme_type' => 'standard',
            'kop_code'    => 'KOP',
            'months'      => 12,
            'filter_id'   => 3,
            'scheme_key'  => $selectedKey,
        ]);

        $loaded = ProductBuyCheckoutPreference::load($session, 0);
        self::assertNotNull($loaded);
        self::assertSame($selectedKey, $loaded['scheme_key']);
        self::assertSame(12, $loaded['months']);
        self::assertNotSame(6, $loaded['months']);
        self::assertNotSame($defaultKey, $loaded['scheme_key']);
    }

    public function testPromoNotConfusedWithSameMonthsStandard(): void
    {
        $schemes = [
            [
                'key'         => ProductSchemeList::keyFromParts('standard', 'KOP', 6, 1),
                'scheme_type' => 'standard',
                'kop_code'    => 'KOP',
                'months'      => 6,
                'filter_id'   => 1,
            ],
            [
                'key'         => ProductSchemeList::keyFromParts('promo', 'KOP', 6, 9),
                'scheme_type' => 'promo',
                'kop_code'    => 'KOP',
                'months'      => 6,
                'filter_id'   => 9,
            ],
        ];
        $preference = [
            'scheme_type' => 'promo',
            'kop_code'    => 'KOP',
            'months'      => 6,
            'filter_id'   => 9,
            'scheme_key'  => $schemes[1]['key'],
        ];

        self::assertSame(
            $schemes[1]['key'],
            FinancingSchemeIdentity::resolveCheckoutSchemeKey($schemes, $preference)
        );
        self::assertNotSame(
            $schemes[0]['key'],
            FinancingSchemeIdentity::resolveCheckoutSchemeKey($schemes, $preference)
        );
    }

    public function testMatcherUsesCheckoutKeyWhenProductKeyEncodingDiffers(): void
    {
        $checkoutKey = ProductSchemeList::keyFromParts('standard', 'POS COM 50', 12, 4);
        $schemes = [
            [
                'key'         => $checkoutKey,
                'scheme_type' => 'standard',
                'kop_code'    => 'POS COM 50',
                'months'      => 12,
                'filter_id'   => 4,
            ],
        ];
        // Preference carries decoded kop + rawurlencode-style key from Product list.
        $preference = [
            'scheme_type' => 'Standard',
            'kop_code'    => ' POS COM 50 ',
            'months'      => '12',
            'filter_id'   => '4',
            'scheme_key'  => 'standard|' . rawurlencode('POS COM 50') . '|12|4',
        ];

        self::assertSame(
            $checkoutKey,
            FinancingSchemeIdentity::resolveCheckoutSchemeKey($schemes, $preference)
        );
    }

    public function testInvalidPreferenceDoesNotForceStaleScheme(): void
    {
        $schemes = [
            [
                'key'         => 'standard|KOP|6|1',
                'scheme_type' => 'standard',
                'kop_code'    => 'KOP',
                'months'      => 6,
                'filter_id'   => 1,
            ],
        ];
        $preference = [
            'scheme_type' => 'promo',
            'kop_code'    => 'MISSING',
            'months'      => 24,
            'filter_id'   => 99,
            'scheme_key'  => 'promo|MISSING|24|99',
        ];
        self::assertNull(FinancingSchemeIdentity::resolveCheckoutSchemeKey($schemes, $preference));
    }

    public function testUserOverrideStopsReapplication(): void
    {
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 1,
            'scheme_type' => 'standard',
            'kop_code'    => 'KOP',
            'months'      => 12,
            'filter_id'   => 3,
            'scheme_key'  => 'standard|KOP|12|3',
        ]);
        ProductBuyCheckoutPreference::markSchemeUserOverride($session);

        $schemes = [
            [
                'key'         => 'standard|KOP|12|3',
                'scheme_type' => 'standard',
                'kop_code'    => 'KOP',
                'months'      => 12,
                'filter_id'   => 3,
            ],
        ];
        $preference = ProductBuyCheckoutPreference::load($session, 0);
        self::assertNotNull($preference);
        self::assertNull(ProductBuyCheckoutPreference::resolveSchemeKey($schemes, $preference));
    }

    public function testSuccessfulMatchMarksPreferenceWithoutClearing(): void
    {
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 1,
            'scheme_type' => 'standard',
            'kop_code'    => 'KOP',
            'months'      => 12,
            'filter_id'   => 3,
            'scheme_key'  => 'standard|KOP|12|3',
        ]);
        ProductBuyCheckoutPreference::markSchemeMatched($session, 'standard|KOP|12|3');
        $loaded = ProductBuyCheckoutPreference::load($session, 0);
        self::assertNotNull($loaded);
        self::assertTrue($loaded['scheme_matched']);
        self::assertSame('standard|KOP|12|3', $loaded['matched_scheme_key']);
        self::assertFalse($loaded['scheme_user_override']);
    }

    public function testProductJsStashIncludesCanonicalSchemeKey(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_product.js');
        self::assertStringContainsString('scheme_key: scheme.key', $js);
        self::assertStringContainsString('buildBuyPreferencePayload', $js);
    }

    public function testCheckoutJsUsesBuyPreferenceBeforeDefaultAndSupportsOverride(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/mt_uni_credit_checkout.js');
        self::assertStringContainsString('buy_preference_scheme_key', $js);
        self::assertStringContainsString('notifyBuySchemeUserOverride', $js);
        self::assertStringContainsString('scheme_override_url', $js);
        self::assertMatchesRegularExpression(
            '/schemeSelect\(\)\?\.addEventListener\("change"[\s\S]*?notifyBuySchemeUserOverride\(\)/s',
            $js
        );
    }

    public function testCheckoutControllerAppliesExactPreferenceToPresenter(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/catalog/controller/payment/mt_uni_credit.php');
        self::assertStringContainsString('resolveBuyPreferenceSchemeKey', $src);
        self::assertStringContainsString('markSchemeMatched', $src);
        self::assertStringContainsString('markBuySchemeOverride', $src);
        self::assertStringContainsString('scheme_override_url', $src);
        self::assertMatchesRegularExpression(
            '/buy_preference_scheme_key[\s\S]*?preferred_scheme_key[\s\S]*?preferred_scheme_key/s',
            $src
        );
    }

    public function testFirstInstallmentIsNotRequiredForIdentityMatch(): void
    {
        $schemes = [
            [
                'key'         => 'standard|KOP|12|3',
                'scheme_type' => 'standard',
                'kop_code'    => 'KOP',
                'months'      => 12,
                'filter_id'   => 3,
            ],
        ];
        $preference = [
            'scheme_type'       => 'standard',
            'kop_code'          => 'KOP',
            'months'            => 12,
            'filter_id'         => 3,
            'first_installment' => 999.99, // calculated input — ignored for identity
        ];
        self::assertSame(
            'standard|KOP|12|3',
            FinancingSchemeIdentity::resolveCheckoutSchemeKey($schemes, $preference)
        );
    }

    public function testPreferenceNotClearedWhenOffersUnavailableYet(): void
    {
        $session = [];
        ProductBuyCheckoutPreference::save($session, 0, [
            'product_id'  => 1,
            'scheme_type' => 'standard',
            'kop_code'    => 'KOP',
            'months'      => 12,
            'filter_id'   => 3,
            'scheme_key'  => 'standard|KOP|12|3',
        ]);

        // Empty offer list → no match, but preference must remain for later UniCredit render.
        self::assertNull(ProductBuyCheckoutPreference::resolveSchemeKey([], ProductBuyCheckoutPreference::load($session, 0) ?? []));
        self::assertNotNull(ProductBuyCheckoutPreference::load($session, 0));
    }
}
