<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\MtUniCredit;

final class ConsentResolver
{
    /**
     * @param array<string, mixed> $shop
     * @return list<array{id:int,name:string,url:string,mandatory:bool,has_checkbox:bool,interest_scope:string}>
     */
    public function normalize(array $shop): array
    {
        $raw = array_key_exists('consents', $shop) ? $shop['consents'] : [];
        if (is_string($raw)) {
            if (!str_starts_with(ltrim($raw), '[')) throw self::refusal();
            try { $raw = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw self::refusal(); }
        }
        if (!is_array($raw) || !array_is_list($raw)) throw self::refusal();

        $result = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mandatory = $this->flag($item['mandatory'] ?? 0);
            $name = is_string($item['name'] ?? null) ? trim(strip_tags($item['name'])) : '';
            $id = $item['id'] ?? null;
            $validId = (is_int($id) && $id > 0)
                || (is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id) === 1 && (string) (int) $id === $id)
                || (is_float($id) && is_finite($id) && $id >= 1 && $id < PHP_INT_MAX && floor($id) === $id);
            if ($name === '' || !$validId) {
                if ($mandatory) throw self::refusal();
                continue; // Unusable optional informational entries follow snapshot-validator semantics.
            }
            $url = $item['url'] ?? '';
            if ($mandatory && $url !== null && !is_string($url)) throw self::refusal();
            $result[] = [
                'id'           => (int) $id,
                'name'         => $name,
                'url'          => is_string($url) ? (filter_var($url, FILTER_VALIDATE_URL) ?: '') : '',
                'mandatory'    => $mandatory,
                'has_checkbox' => $mandatory,
                'interest_scope' => self::scope($item['interest_scope'] ?? null),
            ];
        }

        usort($result, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);

        return $result;
    }

    /**
     * @param array<string, mixed> $shop
     * @param mixed $accepted
     * @return int[]
     */
    public function validate(array $shop, $accepted): array
    {
        return $this->validateDescriptors($this->applicable($this->normalize($shop), 'unknown'), $accepted);
    }

    public static function scope(mixed $value): string
    {
        return is_string($value) && in_array($value, ['all', 'zero_interest', 'nonzero_interest'], true) ? $value : 'all';
    }

    public function applicable(array $descriptors, string $classification): array
    {
        foreach ($descriptors as $descriptor) {
            if ($descriptor['interest_scope'] !== 'all' && $classification === 'unknown') {
                throw self::refusal();
            }
        }
        return array_values(array_filter($descriptors, static fn(array $d): bool => $d['interest_scope'] === 'all' || $d['interest_scope'] === $classification));
    }

    public function validateDescriptors(array $descriptors, mixed $accepted): array
    {
        if (!is_array($accepted) || !array_is_list($accepted)) throw self::refusal();
        $mandatory = array_column(array_filter($descriptors, static fn(array $d): bool => $d['mandatory']), 'id');
        $ids = [];
        foreach ($accepted as $id) {
            if (is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id) === 1 && (string) (int) $id === $id) $id = (int) $id;
            if (!is_int($id) || $id <= 0 || !in_array($id, $mandatory, true) || in_array($id, $ids, true)) throw self::refusal();
            $ids[] = $id;
        }
        sort($ids, SORT_NUMERIC);
        sort($mandatory, SORT_NUMERIC);
        if ($ids !== $mandatory) throw self::refusal();
        return $ids;
    }

    public static function refusal(): ProductFinancingFlowException
    {
        return new ProductFinancingFlowException('invalid_consent', 'Моля, презаредете условията и приемете приложимите задължителни съгласия.', ['consents' => 'Необходима е актуална проверка на съгласията.']);
    }

    /** @param mixed $value */
    private function flag($value): bool
    {
        return in_array($value, [1, '1', true, 'yes', 'on', 'true'], true);
    }
}
