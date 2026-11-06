<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

/** The sole codec for UNI-CONSENT-001/oc4 v1. Hashes detect corruption, not malicious DB edits. */
final class ConsentContext
{
    private const NUMBERS = ['price', 'first_installment', 'financed_amount', 'monthly_installment', 'total_payable', 'glp', 'gpr'];

    public static function conditions(array $calculation): array
    {
        $out = [];
        foreach (['scheme_type', 'kop_code'] as $key) {
            if (!is_string($calculation[$key] ?? null) || $calculation[$key] === '') throw ConsentResolver::refusal();
            $out[$key] = $calculation[$key];
        }
        if (!in_array($out['scheme_type'], ['standard', 'promo'], true)) throw ConsentResolver::refusal();
        foreach (['months', 'filter_id'] as $key) {
            if (!is_int($calculation[$key] ?? null) || $calculation[$key] < ($key === 'months' ? 1 : 0)) throw ConsentResolver::refusal();
            $out[$key] = $calculation[$key];
        }
        foreach (self::NUMBERS as $key) {
            $v = $calculation[$key] ?? null;
            if ((!is_int($v) && !is_float($v)) || !is_finite((float) $v)) throw ConsentResolver::refusal();
            $out[$key] = (float) $v;
        }
        if ($out['price'] <= 0 || $out['financed_amount'] <= 0 || $out['monthly_installment'] <= 0 || $out['first_installment'] < 0) throw ConsentResolver::refusal();
        return $out;
    }

    public static function fromCalculation(CalculationResult $r): array
    {
        return self::conditions(['scheme_type' => $r->scheme->type, 'kop_code' => $r->scheme->kopCode, 'months' => $r->scheme->months,
            'filter_id' => $r->scheme->filterId, 'price' => $r->price, 'first_installment' => $r->firstInstallment->amount,
            'financed_amount' => $r->financedAmount, 'monthly_installment' => $r->monthlyInstallment, 'total_payable' => $r->totalPayable, 'glp' => $r->glp, 'gpr' => $r->gpr]);
    }

    public static function frozenCalculation(array $record): CalculationResult
    {
        if ($record['finalized'] === null) throw ConsentResolver::refusal();
        $c = $record['issued']['context']['conditions'];
        return new CalculationResult(
            new AvailableScheme($c['scheme_type'], $c['kop_code'], $c['months'], $c['filter_id'], null, []),
            $c['price'], new FirstInstallmentState($c['first_installment'], false, $c['first_installment'] > 0.0),
            $c['financed_amount'], $c['monthly_installment'], $c['total_payable'], $c['glp'], $c['gpr']
        );
    }

    /** Server-only seed, containing no customer data. Commerce is bound by the unchanged selection hash. */
    public static function seed(array $shop, array $calculation, ?int $orderId = null, ?string $commerceHash = null): array
    {
        $conditions = self::conditions($calculation);
        $interest = ConsentInterestAuthority::classify($shop, $conditions['kop_code'], $conditions['months']);
        $descriptors = (new ConsentResolver())->normalize($shop);
        $applicable = (new ConsentResolver())->applicable($descriptors, $interest['classification']);
        if (count(array_unique(array_column($descriptors, 'id'))) !== count($descriptors)) throw ConsentResolver::refusal();
        return ['has_scoped' => count(array_filter($descriptors, static fn(array $d): bool => $d['interest_scope'] !== 'all')) > 0, 'commerce_hash' => $commerceHash ?? self::commerce([]), 'conditions' => $conditions, 'interest' => $interest, 'descriptors' => $applicable,
            'applicable_ids' => array_column($applicable, 'id'),
            'mandatory_ids' => array_values(array_column(array_filter($applicable, static fn(array $d): bool => $d['mandatory']), 'id')),
            'existing_order_id' => $orderId, 'process' => ShopConfigurationFlags::isSecondaryProcess($shop) ? 2 : 1];
    }

    /** A separate consent binding: existing operation/selection algorithms are untouched. No raw option text is retained. */
    public static function commerce(array $products): string
    {
        $rows = [];
        foreach ($products as $product) {
            if (!is_array($product) || (int) ($product['product_id'] ?? 0) <= 0 || (int) ($product['quantity'] ?? 0) <= 0) throw ConsentResolver::refusal();
            $row = ['product_id' => (int) $product['product_id'], 'quantity' => (int) $product['quantity']];
            foreach (['price','total','tax'] as $key) {
                $value = $product[$key] ?? ($key === 'tax' ? 0 : null);
                if (!is_numeric($value) || !is_finite((float) $value)) throw ConsentResolver::refusal();
                $row[$key] = number_format((float) $value, 4, '.', '');
            }
            $options = [];
            foreach ($product['option'] ?? [] as $option) {
                if (!is_array($option)) throw ConsentResolver::refusal();
                $options[] = ['option_id' => (int) ($option['product_option_id'] ?? 0), 'value_id' => (int) ($option['product_option_value_id'] ?? 0),
                    'value_hash' => self::hash((string) ($option['value'] ?? '')), 'type' => (string) ($option['type'] ?? '')];
            }
            usort($options, static fn(array $a, array $b): int => strcmp(self::json($a), self::json($b)));
            $row['options'] = $options;
            $rows[] = $row;
        }
        usort($rows, static fn(array $a, array $b): int => strcmp(self::json($a), self::json($b)));
        return self::hash($rows);
    }

    public static function productCommerce(OpenCartProductLine $line): string
    {
        return self::commerce([['product_id' => $line->productId, 'quantity' => $line->quantity,
            'price' => $line->unitPriceExTax, 'total' => round($line->unitPriceExTax * $line->quantity, 4),
            'tax' => $line->unitTax, 'option' => $line->orderOptions]]);
    }

    public static function issue(array $seed, array $binding): string
    {
        $issued = ['binding' => $binding, 'context' => $seed];
        $record = ['contract' => 'UNI-CONSENT-001/oc4', 'version' => 1, 'issued' => $issued, 'issued_hash' => self::hash($issued), 'finalized' => null, 'finalized_hash' => null];
        $json = self::json($record);
        self::decode($json);
        return $json;
    }

    public static function decode(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') throw ConsentResolver::refusal();
        try { $r = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw ConsentResolver::refusal(); }
        if (!is_array($r) || array_keys(self::sorted($r)) !== ['contract', 'finalized', 'finalized_hash', 'issued', 'issued_hash', 'version']
            || $r['contract'] !== 'UNI-CONSENT-001/oc4' || $r['version'] !== 1 || !is_array($r['issued'])
            || !is_string($r['issued_hash']) || !hash_equals(self::hash($r['issued']), $r['issued_hash']) || self::json($r) !== $raw) throw ConsentResolver::refusal();
        self::keys($r['issued'], ['binding', 'context']);
        $b = $r['issued']['binding'] ?? null;
        $c = $r['issued']['context'] ?? null;
        if (!is_array($b) || !is_array($c) || !is_int($b['store_id'] ?? null) || $b['store_id'] < 0
            || !is_string($b['entry_point'] ?? null) || !in_array($b['entry_point'], ['product', 'cart', 'checkout'], true)
            || !is_string($b['submission_token'] ?? null) || !SubmissionTokenGenerator::isValidFormat($b['submission_token'])
            || !is_string($b['created_at'] ?? null) || !is_string($b['expires_at'] ?? null)
            || !in_array($c['process'] ?? null, [1, 2], true)
            || (!is_null($c['existing_order_id'] ?? null) && (!is_int($c['existing_order_id']) || $c['existing_order_id'] <= 0))) throw ConsentResolver::refusal();
        self::keys($b, ['store_id','entry_point','submission_token','operation_key_hash','actor_binding_hash','selection_hash','unicid','created_at','expires_at']);
        self::keys($c, ['has_scoped','commerce_hash','conditions','interest','descriptors','applicable_ids','mandatory_ids','existing_order_id','process']);
        if (!is_string($c['commerce_hash']) || !PersistenceHashValidator::isSha256Hex($c['commerce_hash'])) throw ConsentResolver::refusal();
        foreach (['created_at','expires_at'] as $time) if (!self::timestamp($b[$time])) throw ConsentResolver::refusal();
        if ($b['expires_at'] <= $b['created_at']) throw ConsentResolver::refusal();
        if ($b['entry_point'] === 'checkout' && (!is_int($c['existing_order_id']) || $c['existing_order_id'] <= 0)) throw ConsentResolver::refusal();
        foreach (['operation_key_hash', 'actor_binding_hash', 'selection_hash'] as $key) if (!is_string($b[$key] ?? null) || !PersistenceHashValidator::isSha256Hex($b[$key])) throw ConsentResolver::refusal();
        if (!is_string($b['unicid'] ?? null) || $b['unicid'] === '') throw ConsentResolver::refusal();
        if (!is_array($c['conditions'] ?? null) || self::json(self::conditions($c['conditions'])) !== self::json($c['conditions'])) throw ConsentResolver::refusal();
        if (!is_array($c['interest'] ?? null) || !in_array($c['interest']['classification'] ?? null, ['unknown', 'zero_interest', 'nonzero_interest'], true) || !is_array($c['interest']['rows'] ?? null)) throw ConsentResolver::refusal();
        self::keys($c['conditions'], array_merge(['scheme_type','kop_code','months','filter_id'], self::NUMBERS));
        self::keys($c['interest'], ['classification','rows']);
        if (!array_is_list($c['interest']['rows'])) throw ConsentResolver::refusal();
        $classes = [];
        foreach ($c['interest']['rows'] as $interestRow) {
            if (!is_array($interestRow)) throw ConsentResolver::refusal();
            self::keys($interestRow, ['interest','coefficient','classification']);
            if ($interestRow['classification'] === 'unknown') {
                if ($interestRow['interest'] !== null || $interestRow['coefficient'] !== null) throw ConsentResolver::refusal();
            } else {
                if (!is_float($interestRow['interest']) || !is_finite($interestRow['interest']) || !is_float($interestRow['coefficient'])
                    || !is_finite($interestRow['coefficient']) || $interestRow['coefficient'] <= 0
                    || $interestRow['classification'] !== (abs($interestRow['interest']) <= 0.00001 ? 'zero_interest' : 'nonzero_interest')) throw ConsentResolver::refusal();
            }
            $classes[$interestRow['classification']] = true;
        }
        $expectedClass = count($classes) === 1 ? (string) array_key_first($classes) : 'unknown';
        if ($c['interest']['classification'] !== $expectedClass) throw ConsentResolver::refusal();
        if (!is_bool($c['has_scoped']) || ($c['has_scoped'] && $c['interest']['classification'] === 'unknown')) throw ConsentResolver::refusal();
        $ds = $c['descriptors'] ?? null;
        if (!is_array($ds) || !array_is_list($ds)) throw ConsentResolver::refusal();
        foreach ($ds as $d) {
            if (is_array($d)) self::keys($d, ['id','name','url','mandatory','has_checkbox','interest_scope']);
            if (!is_array($d) || !is_int($d['id'] ?? null) || $d['id'] <= 0 || !is_bool($d['mandatory'] ?? null)
                || ($d['has_checkbox'] ?? null) !== $d['mandatory'] || !is_string($d['name'] ?? null) || !is_string($d['url'] ?? null)
                || !in_array($d['interest_scope'] ?? null, ['all', 'zero_interest', 'nonzero_interest'], true)) throw ConsentResolver::refusal();
        }
        if (!$c['has_scoped'] && array_filter($ds, static fn(array $d): bool => $d['interest_scope'] !== 'all') !== []) throw ConsentResolver::refusal();
        $applicable = (new ConsentResolver())->applicable($ds, $c['interest']['classification']);
        $ids = array_column($ds, 'id');
        if (count(array_unique($ids)) !== count($ids) || $ids !== ($c['applicable_ids'] ?? null) || $applicable !== $ds
            || array_values(array_column(array_filter($ds, static fn(array $d): bool => $d['mandatory']), 'id')) !== ($c['mandatory_ids'] ?? null)) throw ConsentResolver::refusal();
        if ($r['finalized'] === null) {
            if ($r['finalized_hash'] !== null) throw ConsentResolver::refusal();
        } else {
            $f = $r['finalized'];
            if (is_array($f)) self::keys($f, ['attempt_id','accepted_ids','applicable_ids','validated_at']);
            if (!is_array($f) || !is_int($f['attempt_id'] ?? null) || $f['attempt_id'] <= 0 || !is_string($f['validated_at'] ?? null)
                || !self::timestamp($f['validated_at']) || $f['validated_at'] < $b['created_at'] || $f['validated_at'] >= $b['expires_at']
                || ($f['applicable_ids'] ?? null) !== $ids || ($f['accepted_ids'] ?? null) !== $c['mandatory_ids']
                || !is_string($r['finalized_hash']) || !hash_equals(self::hash(['issued_hash' => $r['issued_hash'], 'acceptance' => $f]), $r['finalized_hash'])) throw ConsentResolver::refusal();
        }
        return $r;
    }

    public static function classify(mixed $raw): string
    {
        if ($raw === null) return 'legacy_absent';
        try { return self::decode($raw)['finalized'] === null ? 'issued' : 'finalized'; } catch (ProductFinancingFlowException) { return 'malformed'; }
    }

    public static function finalize(string $raw, int $attemptId, mixed $accepted, string $time): string
    {
        $r = self::decode($raw);
        if ($r['finalized'] !== null) return $raw; // persisted A is authoritative; POST B never replaces it.
        $c = $r['issued']['context'];
        $ids = (new ConsentResolver())->validateDescriptors($c['descriptors'], $accepted);
        $r['finalized'] = ['attempt_id' => $attemptId, 'accepted_ids' => $ids, 'applicable_ids' => $c['applicable_ids'], 'validated_at' => $time];
        $r['finalized_hash'] = self::hash(['issued_hash' => $r['issued_hash'], 'acceptance' => $r['finalized']]);
        $final = self::json($r);
        self::decode($final);
        return $final;
    }

    public static function assertBinding(array $r, array $row): void
    {
        foreach ($r['issued']['binding'] as $key => $value) {
            $actual = $key === 'store_id' ? (int) ($row[$key] ?? -1) : ($row[$key] ?? null);
            if ($actual !== $value) throw ConsentResolver::refusal();
        }
        if ($r['finalized'] !== null && $r['finalized']['attempt_id'] !== (int) ($row['attempt_id'] ?? 0)) throw ConsentResolver::refusal();
    }

    public static function publicContext(array $row): array
    {
        $r = self::decode($row['consent_context_json'] ?? null);
        self::assertBinding($r, $row);
        $c = $r['issued']['context'];
        return ['fingerprint' => $r['issued_hash'], 'descriptors' => $c['descriptors'], 'mandatory_ids' => $c['mandatory_ids'],
            'classification' => $c['interest']['classification'], 'selection_hash' => $r['issued']['binding']['selection_hash']];
    }

    private static function keys(array $value, array $keys): void
    {
        sort($keys, SORT_STRING);
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        if ($actual !== $keys) throw ConsentResolver::refusal();
    }

    private static function timestamp(string $value): bool
    {
        try {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
            return $date !== false && $date->format('Y-m-d H:i:s') === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function json(mixed $value): string
    {
        try { return json_encode(self::sorted($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION); }
        catch (\JsonException) { throw ConsentResolver::refusal(); }
    }
    public static function hash(mixed $value): string { return hash('sha256', self::json($value)); }
    private static function sorted(mixed $v): mixed
    {
        if (!is_array($v)) return $v;
        if (!array_is_list($v)) ksort($v, SORT_STRING);
        foreach ($v as &$item) $item = self::sorted($item);
        return $v;
    }
}
