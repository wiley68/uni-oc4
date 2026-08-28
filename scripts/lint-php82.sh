#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHP82="${PHP82:-/usr/bin/php8.2}"

if [[ ! -x "${PHP82}" ]]; then
    echo "FAIL: PHP 8.2 binary not found at ${PHP82}" >&2
    echo "Set PHP82 to a PHP 8.2 CLI without changing system defaults." >&2
    exit 1
fi

echo "Using ${PHP82} ($("${PHP82}" -r 'echo PHP_VERSION;'))"

mapfile -t files < <(
    find "${ROOT}/admin" "${ROOT}/catalog" "${ROOT}/system" -type f -name '*.php' 2>/dev/null \
        | sort
)

if [[ ${#files[@]} -eq 0 ]]; then
    echo "OK: no production PHP files to lint (Phase 0)."
    exit 0
fi

status=0
for file in "${files[@]}"; do
    if ! "${PHP82}" -l "${file}"; then
        status=1
    fi
done

if [[ "${status}" -eq 0 ]]; then
    echo "OK: PHP 8.2 lint passed (${#files[@]} files)."
fi

exit "${status}"
