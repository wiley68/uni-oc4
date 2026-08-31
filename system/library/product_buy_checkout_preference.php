<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/**
 * Transient Product popup „Купи“ handoff into Checkout (payment + scheme preference).
 *
 * Not a financing attempt — only UX preselection after native cart.add.
 *
 * Native OC4 may reset session.payment_method on shipping_method.save (and address saves).
 * Product Buy intent is independent of that native state until explicitly overridden or expired.
 */
final class ProductBuyCheckoutPreference
{
    public const SESSION_KEY = 'mt_uni_credit_product_buy_preference';

    public const FLOW = 'product_buy';

    public const TTL_SECONDS = 1800;

    public const JSON_PREFERRED_PAYMENT_KEY = 'mt_uni_credit_preferred_payment';

    /**
     * @param array<string, mixed> $sessionData
     * @param array{
     *     product_id:int,
     *     scheme_type:string,
     *     kop_code:string,
     *     months:int,
     *     filter_id:int,
     *     scheme_key?:string,
     *     first_installment?:float|int|string
     * } $selection
     */
    public static function save(array &$sessionData, int $storeId, array $selection): void
    {
        $schemeType = FinancingSchemeIdentity::normalizeSchemeType($selection['scheme_type'] ?? '');
        $kopCode = FinancingSchemeIdentity::normalizeKopCode($selection['kop_code'] ?? '');
        $months = FinancingSchemeIdentity::normalizeMonths($selection['months'] ?? 0);
        $filterId = FinancingSchemeIdentity::normalizeFilterId($selection['filter_id'] ?? 0);
        $schemeKey = trim((string) ($selection['scheme_key'] ?? ''));
        if ($schemeKey === '' && $schemeType !== '' && $kopCode !== '' && $months > 0) {
            $schemeKey = ProductSchemeList::keyFromParts($schemeType, $kopCode, $months, $filterId);
        }

        $sessionData[self::SESSION_KEY] = [
            'flow'                    => self::FLOW,
            'source'                  => 'product_buy',
            'store_id'                => $storeId,
            'product_id'              => (int) ($selection['product_id'] ?? 0),
            'scheme_type'             => $schemeType,
            'kop_code'                => $kopCode,
            'months'                  => $months,
            'filter_id'               => $filterId,
            'scheme_key'              => $schemeKey,
            'first_installment'       => (float) ($selection['first_installment'] ?? 0),
            'prefer_payment'          => true,
            'payment_code'            => ModuleConstants::PAYMENT_OPTION_CODE,
            'payment_user_overridden' => false,
            'scheme_matched'          => false,
            'scheme_user_override'    => false,
            'created_at'              => time(),
        ];
    }

    /**
     * @param array<string, mixed> $sessionData
     * @return array<string, mixed>|null
     */
    public static function load(array &$sessionData, int $storeId): ?array
    {
        $raw = $sessionData[self::SESSION_KEY] ?? null;
        if (!is_array($raw)) {
            return null;
        }

        if ((string) ($raw['flow'] ?? '') !== self::FLOW) {
            self::clear($sessionData);

            return null;
        }

        if ((int) ($raw['store_id'] ?? -1) !== $storeId) {
            self::clear($sessionData);

            return null;
        }

        $createdAt = (int) ($raw['created_at'] ?? 0);
        if ($createdAt <= 0 || (time() - $createdAt) > self::TTL_SECONDS) {
            self::clear($sessionData);

            return null;
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $sessionData
     */
    public static function clear(array &$sessionData): void
    {
        unset($sessionData[self::SESSION_KEY]);
    }

    /**
     * Customer manually changed UniCredit scheme in Checkout — stop re-forcing Product preference.
     *
     * @param array<string, mixed> $sessionData
     */
    public static function markSchemeUserOverride(array &$sessionData): void
    {
        $raw = $sessionData[self::SESSION_KEY] ?? null;
        if (!is_array($raw)) {
            return;
        }
        $raw['scheme_user_override'] = true;
        $sessionData[self::SESSION_KEY] = $raw;
    }

    /**
     * Informational: last time a Checkout UniCredit panel matched Product scheme.
     * Does NOT consume preference — native confirm rerenders may destroy that panel.
     *
     * @param array<string, mixed> $sessionData
     */
    public static function markSchemeMatched(array &$sessionData, string $checkoutSchemeKey): void
    {
        $raw = $sessionData[self::SESSION_KEY] ?? null;
        if (!is_array($raw) || $checkoutSchemeKey === '') {
            return;
        }
        $raw['scheme_matched'] = true;
        $raw['matched_scheme_key'] = $checkoutSchemeKey;
        $sessionData[self::SESSION_KEY] = $raw;
    }

    /**
     * @param array<string, mixed> $preference
     */
    public static function shouldPreferPayment(array $preference): bool
    {
        if (empty($preference['prefer_payment'])) {
            return false;
        }
        if (!empty($preference['payment_user_overridden'])) {
            return false;
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $schemes Presenter scheme rows
     * @param array<string, mixed> $preference
     */
    public static function resolveSchemeKey(array $schemes, array $preference): ?string
    {
        if (!empty($preference['scheme_user_override'])) {
            return null;
        }

        return FinancingSchemeIdentity::resolveCheckoutSchemeKey($schemes, $preference);
    }

    /**
     * Unified scheme rows from a Checkout calculator presenter.
     *
     * @param array<string, mixed> $presenter
     * @return list<array<string, mixed>>
     */
    public static function collectPresenterSchemes(array $presenter): array
    {
        $schemes = [];
        $seen = [];
        foreach (['standard', 'promo'] as $type) {
            $list = $presenter['offers'][$type]['schemes'] ?? null;
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $scheme) {
                if (!is_array($scheme)) {
                    continue;
                }
                $key = (string) ($scheme['key'] ?? '');
                if ($key !== '' && isset($seen[$key])) {
                    continue;
                }
                if ($key !== '') {
                    $seen[$key] = true;
                }
                $schemes[] = $scheme;
            }
        }

        return $schemes;
    }

    /**
     * Initial Checkout scheme precedence (single contract):
     * user override → valid Product Buy preference → Checkout PreferredOffer default.
     *
     * @param array<string, mixed> $presenter Checkout calculator presenter
     * @param array<string, mixed> $sessionData
     * @return array{key:?string, source:string, buy_matched:bool}
     */
    public static function resolveInitialSchemeSelection(
        array $presenter,
        array &$sessionData,
        int $storeId
    ): array {
        $schemes = self::collectPresenterSchemes($presenter);
        $preference = self::load($sessionData, $storeId);

        $defaultKey = self::presenterDefaultSchemeKey($presenter, $schemes);

        if ($preference !== null && !empty($preference['scheme_user_override'])) {
            return [
                'key'         => $defaultKey,
                'source'      => 'user_override',
                'buy_matched' => false,
            ];
        }

        if ($preference !== null) {
            $buyKey = self::resolveSchemeKey($schemes, $preference);
            if ($buyKey !== null && $buyKey !== '') {
                return [
                    'key'         => $buyKey,
                    'source'      => 'product_buy',
                    'buy_matched' => true,
                ];
            }
        }

        return [
            'key'         => $defaultKey,
            'source'      => 'checkout_default',
            'buy_matched' => false,
        ];
    }

    /**
     * @param list<array<string, mixed>> $schemes
     */
    private static function presenterDefaultSchemeKey(array $presenter, array $schemes): ?string
    {
        foreach (['standard', 'promo'] as $type) {
            $key = trim((string) ($presenter['offers'][$type]['preferred_scheme_key'] ?? ''));
            if ($key !== '') {
                return $key;
            }
        }
        if ($schemes === []) {
            return null;
        }
        $key = trim((string) ($schemes[0]['key'] ?? ''));

        return $key !== '' ? $key : null;
    }

    /**
     * Server-rendered Checkout scheme dropdown rows (standard unified list).
     *
     * @param array<string, mixed> $presenter
     * @return list<array{key:string,label:string,selected:bool}>
     */
    public static function buildCheckoutSchemeOptions(array $presenter, ?string $selectedKey): array
    {
        $list = $presenter['offers']['standard']['schemes'] ?? [];
        if (!is_array($list) || $list === []) {
            $list = self::collectPresenterSchemes($presenter);
        }

        $options = [];
        foreach ($list as $scheme) {
            if (!is_array($scheme)) {
                continue;
            }
            $key = trim((string) ($scheme['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $months = (int) ($scheme['months'] ?? 0);
            $label = $months > 0 ? $months . ' месеца' : $key;
            $description = trim((string) ($scheme['description'] ?? ''));
            if ($description !== '') {
                $label .= ' - ' . $description;
            }
            $options[] = [
                'key'      => $key,
                'label'    => $label,
                'selected' => $selectedKey !== null && $selectedKey !== '' && $key === $selectedKey,
            ];
        }

        return $options;
    }

    /**
     * Apply UniCredit payment method into session when listed in discovered methods.
     *
     * Native shipping_method.save unsets payment_method — this re-applies Product Buy intent
     * when payment methods are rediscovered. Does not treat native reset as user override.
     *
     * @param array<string, mixed> $sessionData
     * @param array<string, mixed> $paymentMethods Native session.payment_methods shape
     */
    public static function applyPaymentIfAvailable(array &$sessionData, array $paymentMethods, int $storeId): bool
    {
        $preference = self::load($sessionData, $storeId);
        if ($preference === null || !self::shouldPreferPayment($preference)) {
            return false;
        }

        $row = self::findUniCreditOption($paymentMethods);
        if ($row === null) {
            // UniCredit unavailable for current cart — do not fake-select.
            if (PaymentIdentity::matchesStoredPayment($sessionData['payment_method'] ?? null)) {
                unset($sessionData['payment_method']);
            }
            $preference['prefer_payment'] = false;
            $sessionData[self::SESSION_KEY] = $preference;

            return false;
        }

        $sessionData['payment_method'] = $row;

        return true;
    }

    /**
     * Put UniCredit extension first so OC4 payment modal "first radio" fallback selects it
     * when #input-payment-code is empty after native shipping reset.
     *
     * @param array<string, mixed> $paymentMethods
     * @return array<string, mixed>
     */
    public static function preferUniCreditFirst(array $paymentMethods): array
    {
        $code = ModuleConstants::PAYMENT_CODE;
        if (!isset($paymentMethods[$code]) || !is_array($paymentMethods[$code])) {
            return $paymentMethods;
        }
        $uni = $paymentMethods[$code];
        unset($paymentMethods[$code]);

        return [$code => $uni] + $paymentMethods;
    }

    /**
     * @param array<string, mixed> $paymentMethods
     * @return array{code:string,name:string}|null
     */
    public static function preferredPaymentPayload(array $paymentMethods): ?array
    {
        $row = self::findUniCreditOption($paymentMethods);
        if ($row === null) {
            return null;
        }

        return [
            'code' => (string) ($row['code'] ?? ModuleConstants::PAYMENT_OPTION_CODE),
            'name' => (string) ($row['name'] ?? PaymentIdentity::DISPLAY_NAME),
        ];
    }

    /**
     * Apply payment preference + reorder/annotate getMethods JSON for Checkout UI.
     *
     * @param array<string, mixed> $sessionData
     * @param array<string, mixed> $json Decoded getMethods response
     * @return array<string, mixed>
     */
    public static function enrichPaymentMethodsResponse(array &$sessionData, array $json, int $storeId): array
    {
        if (empty($json['payment_methods']) || !is_array($json['payment_methods'])) {
            return $json;
        }

        $methods = $json['payment_methods'];
        $sessionData['payment_methods'] = $methods;
        $applied = self::applyPaymentIfAvailable($sessionData, $methods, $storeId);
        if (!$applied) {
            return $json;
        }

        $ordered = self::preferUniCreditFirst($methods);
        $json['payment_methods'] = $ordered;
        $sessionData['payment_methods'] = $ordered;
        $payload = self::preferredPaymentPayload($ordered);
        if ($payload !== null) {
            $json[self::JSON_PREFERRED_PAYMENT_KEY] = $payload;
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $paymentMethods
     * @return array<string, mixed>|null
     */
    private static function findUniCreditOption(array $paymentMethods): ?array
    {
        $code = ModuleConstants::PAYMENT_OPTION_CODE;
        $parts = explode('.', $code, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$extension, $option] = $parts;
        $row = $paymentMethods[$extension]['option'][$option] ?? null;
        if (!is_array($row) || (string) ($row['code'] ?? '') !== $code) {
            return null;
        }

        return $row;
    }

    /**
     * Explicit customer payment save away from UniCredit cancels Product Buy handoff.
     * Native shipping_method.save unset of payment_method is NOT a user override and must not call this.
     *
     * @param array<string, mixed> $sessionData
     */
    public static function clearIfPaymentChangedAway(array &$sessionData): void
    {
        if (!isset($sessionData[self::SESSION_KEY])) {
            return;
        }
        if (PaymentIdentity::matchesStoredPayment($sessionData['payment_method'] ?? null)) {
            return;
        }
        self::clear($sessionData);
    }
}
